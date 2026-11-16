<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Tickets\CannedResponseRenderer;
use App\Services\Tickets\TicketHtml;
use App\Ticket;
use App\TicketCannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff reusable replies. Shared ones belong to everybody on the support team
 * and can be changed by any of them; personal ones only by their owner.
 */
class TicketCannedResponses extends Controller
{
    public function __construct(
        private TicketHtml $html,
        private CannedResponseRenderer $renderer,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('manage', Ticket::class);

        return inertia('Admin/Tickets/CannedResponses', [
            'responses' => TicketCannedResponse::usableBy($request->user())->orderBy('title')->get()->map(fn (TicketCannedResponse $response) => [
                'id' => $response->id,
                'title' => $response->title,
                'body_html' => $response->body_html,
                'scope' => $response->scope,
                'mine' => $response->owner_id === $request->user()->id,
                'can_change' => $response->canBeChangedBy($request->user()),
            ])->values()->all(),
            'variables' => ['requester.name', 'customer.name', 'org.name', 'ticket.reference', 'ticket.subject', 'ticket.url', 'agent.name', 'assignee.name'],
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.canned.title')],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $data = $this->validated($request);

        $response = new TicketCannedResponse(['title' => $data['title']]);
        $response->body_html = $this->html->prepare($data['body'])['html'];
        $response->scope = $data['scope'];
        $response->owner_id = $request->user()->id;
        $response->save();

        return back()->with('success', __('tickets.canned.saved'));
    }

    public function update(Request $request, TicketCannedResponse $response): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);
        abort_unless($this->visibleTo($response, $request) && $response->canBeChangedBy($request->user()), 404);

        $data = $this->validated($request);

        $response->title = $data['title'];
        $response->body_html = $this->html->prepare($data['body'])['html'];
        $response->scope = $data['scope'];
        $response->save();

        return back()->with('success', __('tickets.canned.saved'));
    }

    public function destroy(Request $request, TicketCannedResponse $response): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);
        abort_unless($this->visibleTo($response, $request) && $response->canBeChangedBy($request->user()), 404);

        $response->delete();

        return back()->with('success', __('tickets.canned.deleted'));
    }

    /**
     * The response with this ticket's details filled in, ready to drop into
     * the reply box. Nothing is sent.
     */
    public function render(Request $request, int $number, TicketCannedResponse $response): JsonResponse
    {
        Gate::authorize('manage', Ticket::class);
        abort_unless($this->visibleTo($response, $request), 404);

        $ticket = Ticket::where('number', $number)->firstOrFail();

        return response()->json(['html' => $this->renderer->render($response, $ticket, $request->user())]);
    }

    /**
     * @return array{title: string, body: string, scope: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string'],
            'scope' => ['required', Rule::in([TicketCannedResponse::SHARED, TicketCannedResponse::PERSONAL])],
        ]);
    }

    /**
     * Somebody else's personal response does not exist as far as you can tell.
     */
    private function visibleTo(TicketCannedResponse $response, Request $request): bool
    {
        return $response->scope === TicketCannedResponse::SHARED || $response->owner_id === $request->user()->id;
    }
}
