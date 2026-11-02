<?php

namespace App\Http\Resources\Tickets;

use App\Services\Tickets\TicketService;
use App\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public message as the customer sees it. Callers must only pass public
 * messages (Ticket::publicMessages()); this resource refuses internal ones.
 *
 * @mixin TicketMessage
 */
class OrgMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        abort_if($this->isInternal(), 500, 'Internal ticket message passed to a customer resource.');

        $viewer = $request->user();
        $mine = $viewer && $this->author_id === $viewer->id;

        return [
            'id' => $this->id,
            'kind' => $this->author_kind,
            'author' => $this->author?->name ?? __('tickets.deleted_user'),
            'mine' => $mine,
            'body_html' => $this->redacted_at ? '' : $this->body_html,
            'redacted' => $this->redacted_at !== null,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_edit' => $mine && app(TicketService::class)->canEditMessage($this->resource, $viewer, false),
        ];
    }
}
