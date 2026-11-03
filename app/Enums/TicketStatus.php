<?php

namespace App\Enums;

enum TicketStatus: string
{
    case OPEN = 'open';

    // Waiting on support
    case PENDING_SUPPORT = 'pending_support';

    // Waiting on the customer
    case PENDING_CUSTOMER = 'pending_customer';

    case ON_HOLD = 'on_hold';

    case RESOLVED = 'resolved';

    case CLOSED = 'closed';

    public function label(): string
    {
        return __('tickets.status.'.$this->value);
    }

    /**
     * Still being worked on. Resolved and closed tickets are done.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::RESOLVED, self::CLOSED], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return collect(self::cases())
            ->filter(fn (self $status) => $status->isOpen())
            ->map(fn (self $status) => $status->value)
            ->values()
            ->all();
    }
}
