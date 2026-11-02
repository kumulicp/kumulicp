<?php

namespace App\Http\Resources\Tickets;

use App\Services\Tickets\TicketUnread;
use App\Ticket;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as the customer sees it. Staff are shown by name only; priority,
 * tags and anything internal are never included.
 *
 * @mixin Ticket
 */
class OrgTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'reference' => $this->reference,
            'unread' => app(TicketUnread::class)->isUnread($this->resource),
            'subject' => $this->subject,
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'open' => $this->status->isOpen()],
            'impact' => ['value' => $this->impact->value, 'label' => $this->impact->label()],
            'category' => $this->category?->name,
            'app' => $this->appInstance?->label,
            'requester' => $this->person($this->requester),
            'customer_assignee' => $this->person($this->customerAssignee),
            'support_assignee' => $this->supportAssignee ? ['name' => $this->supportAssignee->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'url' => '/support/tickets/'.$this->number,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
