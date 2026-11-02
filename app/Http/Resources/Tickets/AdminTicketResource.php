<?php

namespace App\Http\Resources\Tickets;

use App\Ticket;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ticket as staff see it.
 *
 * @mixin Ticket
 */
class AdminTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->number,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'type' => ['value' => $this->type->value, 'label' => $this->type->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label(), 'open' => $this->status->isOpen()],
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'impact' => ['value' => $this->impact->value, 'label' => $this->impact->label()],
            'category_id' => $this->category_id,
            'category' => $this->category?->name,
            'app' => $this->appInstance?->label,
            'organization' => $this->organization ? ['id' => $this->organization->id, 'name' => $this->organization->name] : null,
            'requester' => $this->person($this->requester),
            'customer_assignee' => $this->person($this->customerAssignee),
            'support_assignee' => $this->person($this->supportAssignee),
            'tags' => $this->relationLoaded('tags') ? $this->tags->pluck('name')->values() : [],
            'lock_version' => $this->lock_version,
            'first_response_at' => $this->first_response_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'url' => '/admin/support/tickets/'.$this->number,
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
