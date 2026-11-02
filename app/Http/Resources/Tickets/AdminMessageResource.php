<?php

namespace App\Http\Resources\Tickets;

use App\Services\Tickets\AttachmentPresenter;
use App\Services\Tickets\TicketService;
use App\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message (public or internal) as staff see it.
 *
 * @mixin TicketMessage
 */
class AdminMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $mine = $viewer && $this->author_id === $viewer->id;

        return [
            'id' => $this->id,
            'kind' => $this->author_kind,
            'internal' => $this->isInternal(),
            'author' => $this->author?->name ?? __('tickets.deleted_user'),
            'mine' => $mine,
            'body_html' => $this->redacted_at ? '' : $this->body_html,
            'redacted' => $this->redacted_at !== null,
            'attachments' => AttachmentPresenter::collection($this->resource->attachments),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_edit' => $mine && app(TicketService::class)->canEditMessage($this->resource, $viewer, true),
        ];
    }
}
