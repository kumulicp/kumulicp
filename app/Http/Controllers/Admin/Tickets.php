<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AdminUpdateTicketRequest;
use App\Http\Requests\Tickets\BodyRequest;
use App\Http\Resources\Tickets\AdminMessageResource;
use App\Http\Resources\Tickets\AdminTicketResource;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketStaff;
use App\Services\Tickets\TicketTimeline;
use App\Ticket;
use App\TicketCategory;
use App\TicketMessage;
use App\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff side of tickets. Reached only through the admin route group, and every
 * action re-checks the manage ability so a future permission change applies
 * everywhere.
 */
class Tickets extends Controller
{
    public function __construct(
        private TicketService $tickets,
        private TicketStaff $staff,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('manage', Ticket::class);

        $user = $request->user();

        $filters = [
            'assigned' => $this->assignedFilter($request->query('assigned')),
            'status' => $this->statusTab($request->query('status')),
            'priority' => Arr::first(TicketPriority::cases(), fn ($p) => $p->value === $request->query('priority'))?->value,
            'category' => $request->integer('category') ?: null,
            'organization' => $request->integer('organization') ?: null,
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = Ticket::query()->with(['organization', 'requester', 'customerAssignee', 'supportAssignee', 'category']);

        match (true) {
            $filters['assigned'] === 'me' => $query->where('support_assignee_id', $user->id),
            $filters['assigned'] === 'unassigned' => $query->whereNull('support_assignee_id'),
            str_starts_with($filters['assigned'], 'user:') => $query->where('support_assignee_id', (int) substr($filters['assigned'], 5)),
            default => null,
        };

        match ($filters['status']) {
            'open' => $query->whereIn('status', TicketStatus::openValues()),
            'waiting_on_support' => $query->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::PENDING_SUPPORT->value]),
            'waiting_on_customer' => $query->where('status', TicketStatus::PENDING_CUSTOMER->value),
            'on_hold' => $query->where('status', TicketStatus::ON_HOLD->value),
            'resolved' => $query->where('status', TicketStatus::RESOLVED->value),
            'closed' => $query->where('status', TicketStatus::CLOSED->value),
            default => null,
        };

        $query->when($filters['priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['category'], fn ($q, $category) => $q->where('category_id', $category))
            ->when($filters['organization'], fn ($q, $organization) => $q->where('organization_id', $organization));

        if ($filters['q'] !== '') {
            $query->where(function ($query) use ($filters) {
                $query->where('subject', 'like', '%'.addcslashes($filters['q'], '%_\\').'%')
                    ->orWhere('number', (int) preg_replace('/\D/', '', $filters['q']));
            });
        }

        $tickets = $query->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Ticket $ticket) => AdminTicketResource::make($ticket)->resolve($request));

        return inertia('Admin/Tickets/TicketsList', [
            'tickets' => $tickets,
            'filters' => $filters,
            'counts' => [
                'mine_open' => Ticket::where('support_assignee_id', $user->id)->whereIn('status', TicketStatus::openValues())->count(),
                'unassigned_open' => Ticket::whereNull('support_assignee_id')->whereIn('status', TicketStatus::openValues())->count(),
                'open' => Ticket::whereIn('status', TicketStatus::openValues())->count(),
            ],
            'staff' => $this->staffOptions(),
            'categories' => $this->categoryOptions(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'text' => $p->label()])->all(),
            'me' => $user->id,
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title')],
            ],
        ]);
    }

    public function show(Request $request, int $number)
    {
        Gate::authorize('manage', Ticket::class);

        $ticket = Ticket::where('number', $number)->firstOrFail();
        $ticket->load(['organization.plan', 'requester', 'customerAssignee', 'supportAssignee', 'category', 'appInstance', 'tags']);

        return inertia('Admin/Tickets/TicketShow', [
            'ticket' => AdminTicketResource::make($ticket)->resolve($request),
            'messages' => AdminMessageResource::collection($ticket->messages()->with(['author', 'attachments'])->get())->resolve($request),
            'timeline' => (new TicketTimeline)->for($ticket, true),
            'context' => $this->context($ticket),
            'staff' => $this->staffOptions(),
            'members' => User::where('organization_id', $ticket->organization_id)->where('is_allowed', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->all(),
            'statuses' => collect(TicketStatus::cases())->map(fn ($s) => ['value' => $s->value, 'text' => $s->label()])->all(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($p) => ['value' => $p->value, 'text' => $p->label()])->all(),
            'categories' => $this->categoryOptions(),
            'me' => $request->user()->id,
            'attachment_limits' => [
                'files' => config('tickets.attachments.max_files'),
                'file_mb' => (int) floor(config('tickets.attachments.max_file_kb') / 1024),
            ],
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => $ticket->reference],
            ],
        ]);
    }

    public function reply(BodyRequest $request, int $number): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $ticket = Ticket::where('number', $number)->firstOrFail();
        $internal = (bool) $request->validated('internal', false);

        $this->tickets->reply($ticket, $request->user(), $request->validated('body'), asStaff: true, internal: $internal, attachmentIds: $request->validated('attachment_ids', []));

        return redirect('/admin/support/tickets/'.$ticket->number)
            ->with('success', $internal ? __('tickets.note_added') : __('tickets.reply_sent'));
    }

    public function updateMessage(BodyRequest $request, int $number, TicketMessage $message): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $ticket = Ticket::where('number', $number)->firstOrFail();
        abort_unless($message->ticket_id === $ticket->id, 404);
        Gate::authorize('editMessage', [$ticket, $message]);

        $this->tickets->editMessage($message, $request->user(), $request->validated('body'), asStaff: true);

        return redirect('/admin/support/tickets/'.$ticket->number)->with('success', __('tickets.message_updated'));
    }

    public function update(AdminUpdateTicketRequest $request, int $number): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $ticket = Ticket::where('number', $number)->firstOrFail();
        $user = $request->user();
        $data = $request->validated();

        // Someone else changed the ticket since this page was loaded
        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $ticket->lock_version) {
            throw ValidationException::withMessages(['lock_version' => __('tickets.errors.stale')]);
        }

        if (Arr::hasAny($data, ['subject', 'impact'])) {
            $this->tickets->updateDetails($ticket, $user, Arr::only($data, ['subject', 'impact']), asStaff: true);
        }

        if (isset($data['status'])) {
            $this->tickets->changeStatus($ticket, $user, TicketStatus::from($data['status']), asStaff: true);
        }

        if (isset($data['priority'])) {
            $this->tickets->changePriority($ticket, $user, TicketPriority::from($data['priority']));
        }

        if (array_key_exists('category_id', $data)) {
            $this->tickets->changeCategory($ticket, $user, $data['category_id']);
        }

        foreach (['support_assignee_id' => TicketService::SUPPORT, 'customer_assignee_id' => TicketService::CUSTOMER] as $field => $side) {
            if (array_key_exists($field, $data)) {
                $assignee = $data[$field] ? User::find($data[$field]) : null;
                $this->tickets->assign($ticket, $side, $assignee, $user);
            }
        }

        if (array_key_exists('tags', $data)) {
            $this->tickets->syncTags($ticket, $data['tags'] ?? [], $user);
        }

        $ticket->increment('lock_version');

        return redirect('/admin/support/tickets/'.$ticket->number)->with('success', __('tickets.updated'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $slug = Str::slug($data['name']) ?: Str::random(8);

        TicketCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => $data['name'], 'active' => true, 'sort' => (int) TicketCategory::max('sort') + 1],
        );

        return back()->with('success', __('tickets.admin.category_saved'));
    }

    public function destroyCategory(TicketCategory $category): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        // Archived rather than deleted so old tickets keep their category
        $category->active = false;
        $category->save();

        return back()->with('success', __('tickets.admin.category_archived'));
    }

    /**
     * What staff need to know about the customer without leaving the ticket.
     *
     * @return array<string, mixed>
     */
    private function context(Ticket $ticket): array
    {
        $organization = $ticket->organization;

        return [
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'status' => $organization->status,
                'plan' => $organization->plan?->name,
                'url' => '/admin/organizations/'.$organization->id,
            ] : null,
            'requester' => $ticket->requester ? [
                'name' => $ticket->requester->name,
                'email' => $ticket->requester->email,
            ] : null,
            'apps' => $organization
                ? $organization->app_instances()->orderBy('label')->limit(25)->get(['id', 'label', 'status'])
                    ->map(fn ($app) => ['id' => $app->id, 'label' => $app->label, 'status' => $app->status])->all()
                : [],
            'tasks' => $organization
                ? $organization->tasks()->latest('id')->limit(5)->get()
                    ->map(fn ($task) => [
                        'id' => $task->id,
                        'name' => $task->action_slug ?: $task->action_group,
                        'status' => $task->status->value,
                        'error' => $task->error_message ? Str::limit($task->error_message, 160) : null,
                        'created_at' => $task->created_at?->toIso8601String(),
                    ])->all()
                : [],
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function staffOptions(): array
    {
        return $this->staff->users()->sortBy('name')->values()
            ->map(fn (User $member) => ['id' => $member->id, 'name' => $member->name])->all();
    }

    /**
     * @return list<array{value: int, text: string}>
     */
    private function categoryOptions(): array
    {
        return TicketCategory::where('active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name'])
            ->map(fn (TicketCategory $category) => ['value' => $category->id, 'text' => $category->name])->all();
    }

    private function assignedFilter(mixed $value): string
    {
        $value = is_string($value) ? $value : 'me';

        return in_array($value, ['me', 'all', 'unassigned'], true) || preg_match('/^user:\d+$/', $value) ? $value : 'me';
    }

    private function statusTab(mixed $value): string
    {
        return in_array($value, ['open', 'waiting_on_support', 'waiting_on_customer', 'on_hold', 'resolved', 'closed', 'all'], true) ? $value : 'open';
    }
}
