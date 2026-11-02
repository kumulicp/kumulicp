<?php

namespace App\Enums;

/**
 * What the customer says the problem is doing to them. Customers set impact;
 * only staff set priority.
 */
enum TicketImpact: string
{
    case MINOR = 'minor';

    case DEGRADED = 'degraded';

    case OUTAGE = 'outage';

    public function label(): string
    {
        return __('tickets.impact.'.$this->value);
    }
}
