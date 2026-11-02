<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Services\Tickets\TicketService;
use App\Services\Tickets\TicketStaff;
use App\Ticket;
use App\TicketMessage;
use App\User;
use Illuminate\Support\Facades\Gate;

/**
 * Every member of an organization can see and take part in all of that
 * organization's tickets. Staff (control panel admins, for now) can see all
 * tickets. Staff checks all go through manage() so finer-grained permissions
 * can replace it later in one place.
 */
class TicketPolicy
{
    public function __construct(private TicketStaff $staff, private TicketService $service) {}

    /**
     * Staff access to the admin side of tickets.
     */
    public function manage(User $user): bool
    {
        return Gate::forUser($user)->allows('admin');
    }

    public function viewAny(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->organization_id === $user->organization_id || $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $user->organization_id !== null;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        if (! $this->view($user, $ticket)) {
            return false;
        }

        // A closed ticket has to be reopened first, except by staff
        return $ticket->status !== TicketStatus::CLOSED || $this->manage($user);
    }

    /**
     * Customer-side changes: subject and impact (until staff first respond),
     * the customer assignee, closing and reopening.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function editMessage(User $user, Ticket $ticket, TicketMessage $message): bool
    {
        return $message->ticket_id === $ticket->id
            && $this->view($user, $ticket)
            && $this->service->canEditMessage($message, $user, $this->manage($user));
    }
}
