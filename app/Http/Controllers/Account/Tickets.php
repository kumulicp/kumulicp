<?php

namespace App\Http\Controllers\Account;

use App\Enums\TicketImpact;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\BodyRequest;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Http\Requests\Tickets\UpdateTicketRequest;
use App\Http\Resources\Tickets\OrgMessageResource;
use App\Http\Resources\Tickets\OrgTicketResource;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketStaff;
use App\Services\Tickets\TicketTimeline;
use App\Ticket;
use App\TicketCategory;
use App\TicketMessage;
use App\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Customer side of tickets. Every lookup is scoped to the signed-in user's
 * organization, so another organization's ticket is a 404, not a 403.
 */
class Tickets extends Controller
{
    public function __construct(
        private TicketService $tickets,
        private TicketStaff $staff,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Ticket::class);

        $user = $request->user();
        $organization_id = $user->organization_id;

        $filters = [
            'assigned' => $this->assignedFilter($request->query('assigned')),
            'status' => $this->statusTab($request->query('status')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = Ticket::forOrganization($organization_id)
            ->with(['requester', 'customerAssignee', 'supportAssignee', 'category', 'appInstance']);

        match (true) {
            $filters['assigned'] === 'me' => $query->where('customer_assignee_id', $user->id),
            $filters['assigned'] === 'unassigned' => $query->whereNull('customer_assignee_id'),
            str_starts_with($filters['assigned'], 'user:') => $query->where('customer_assignee_id', (int) substr($filters['assigned'], 5)),
            default => null,
        };

        $this->applyStatusTab($query, $filters['status']);

        if ($filters['q'] !== '') {
            $query->where(function ($query) use ($filters) {
                $query->where('subject', 'like', '%'.addcslashes($filters['q'], '%_\\').'%')
                    ->orWhere('number', (int) preg_replace('/\D/', '', $filters['q']));
            });
        }

        $tickets = $query->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Ticket $ticket) => OrgTicketResource::make($ticket)->resolve($request));

        return inertia('Organization/Tickets/TicketsList', [
            'tickets' => $tickets,
            'filters' => $filters,
            // For the empty state: "0 assigned to you, 12 open in your organization"
            'counts' => [
                'mine_open' => Ticket::forOrganization($organization_id)->where('customer_assignee_id', $user->id)->whereIn('status', TicketStatus::openValues())->count(),
                'organization_open' => Ticket::forOrganization($organization_id)->whereIn('status', TicketStatus::openValues())->count(),
            ],
            'members' => $this->members($user),
            'me' => $user->id,
            'breadcrumbs' => [
                ['label' => __('tickets.title')],
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Ticket::class);

        $user = $request->user();

        return inertia('Organization/Tickets/TicketCreate', [
            'types' => collect(TicketType::cases())->map(fn (TicketType $type) => ['value' => $type->value, 'text' => $type->label()])->all(),
            'impacts' => collect(TicketImpact::cases())->map(fn (TicketImpact $impact) => ['value' => $impact->value, 'text' => $impact->label()])->all(),
            'categories' => TicketCategory::where('active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name'])
                ->map(fn (TicketCategory $category) => ['value' => $category->id, 'text' => $category->name])->all(),
            'apps' => $user->organization->app_instances()->orderBy('label')->get(['id', 'label'])
                ->map(fn ($app) => ['value' => $app->id, 'text' => $app->label])->all(),
            'members' => $this->members($user),
            'me' => $user->id,
            'breadcrumbs' => [
                ['label' => __('tickets.title'), 'url' => '/support/tickets'],
                ['label' => __('tickets.new_ticket')],
            ],
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse|JsonResponse
    {
        $ticket = $this->tickets->create($request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => 'success',
                'reference' => $ticket->reference,
                'url' => '/support/tickets/'.$ticket->number,
            ], 201);
        }

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.created'));
    }

    public function show(Request $request, int $number): Response
    {
        $ticket = $this->find($request, $number);
        Gate::authorize('view', $ticket);

        $ticket->load(['requester', 'customerAssignee', 'supportAssignee', 'category', 'appInstance']);
        $user = $request->user();

        return inertia('Organization/Tickets/TicketShow', [
            'ticket' => OrgTicketResource::make($ticket)->resolve($request),
            'messages' => OrgMessageResource::collection($ticket->publicMessages()->with('author')->get())->resolve($request),
            'timeline' => (new TicketTimeline)->for($ticket, false),
            'can' => [
                'reply' => Gate::allows('reply', $ticket),
                'edit_details' => $ticket->first_response_at === null,
                'close' => $ticket->status->isOpen(),
                'reopen' => ! $ticket->status->isOpen(),
            ],
            'members' => $this->members($user),
            'me' => $user->id,
            'edit_window_minutes' => config('tickets.edit_window_minutes'),
            'breadcrumbs' => [
                ['label' => __('tickets.title'), 'url' => '/support/tickets'],
                ['label' => $ticket->reference],
            ],
        ]);
    }

    public function reply(BodyRequest $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        Gate::authorize('reply', $ticket);

        $this->tickets->reply($ticket, $request->user(), $request->validated('body'), asStaff: false);

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.reply_sent'));
    }

    public function updateMessage(BodyRequest $request, int $number, TicketMessage $message): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        abort_unless($message->ticket_id === $ticket->id && ! $message->isInternal(), 404);
        Gate::authorize('editMessage', [$ticket, $message]);

        $this->tickets->editMessage($message, $request->user(), $request->validated('body'), asStaff: false);

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.message_updated'));
    }

    public function update(UpdateTicketRequest $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        Gate::authorize('update', $ticket);

        $user = $request->user();
        $data = $request->validated();

        if (Arr::hasAny($data, ['subject', 'impact'])) {
            $this->tickets->updateDetails($ticket, $user, Arr::only($data, ['subject', 'impact']), asStaff: false);
        }

        if (array_key_exists('customer_assignee_id', $data)) {
            $assignee = $data['customer_assignee_id'] ? User::find($data['customer_assignee_id']) : null;
            $this->tickets->assign($ticket, TicketService::CUSTOMER, $assignee, $user);
        }

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.updated'));
    }

    public function close(Request $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        Gate::authorize('update', $ticket);

        $this->tickets->changeStatus($ticket, $request->user(), TicketStatus::CLOSED, asStaff: false);

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.closed'));
    }

    public function reopen(Request $request, int $number): RedirectResponse
    {
        $ticket = $this->find($request, $number);
        Gate::authorize('update', $ticket);

        $this->tickets->changeStatus($ticket, $request->user(), TicketStatus::OPEN, asStaff: false);

        return redirect('/support/tickets/'.$ticket->number)->with('success', __('tickets.reopened'));
    }

    private function find(Request $request, int $number): Ticket
    {
        return Ticket::forOrganization($request->user()->organization_id)->where('number', $number)->firstOrFail();
    }

    /**
     * Active members of the user's own organization, for assignee pickers.
     *
     * @return list<array{id: int, name: string}>
     */
    private function members(User $user): array
    {
        return User::where('organization_id', $user->organization_id)
            ->where('is_allowed', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])
            ->all();
    }

    /**
     * me (default) | all | unassigned | user:{id}
     */
    private function assignedFilter(mixed $value): string
    {
        $value = is_string($value) ? $value : 'me';

        return in_array($value, ['me', 'all', 'unassigned'], true) || preg_match('/^user:\d+$/', $value) ? $value : 'me';
    }

    /**
     * open (default) | waiting_on_us | waiting_on_you | resolved | closed | all
     */
    private function statusTab(mixed $value): string
    {
        return in_array($value, ['open', 'waiting_on_us', 'waiting_on_you', 'resolved', 'closed', 'all'], true) ? $value : 'open';
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyStatusTab($query, string $tab): void
    {
        match ($tab) {
            'open' => $query->whereIn('status', TicketStatus::openValues()),
            'waiting_on_us' => $query->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::PENDING_SUPPORT->value, TicketStatus::ON_HOLD->value]),
            'waiting_on_you' => $query->where('status', TicketStatus::PENDING_CUSTOMER->value),
            'resolved' => $query->where('status', TicketStatus::RESOLVED->value),
            'closed' => $query->where('status', TicketStatus::CLOSED->value),
            default => null,
        };
    }
}
