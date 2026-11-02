<?php

namespace App\Enums;

enum TicketPriority: string
{
    case LOW = 'low';

    case NORMAL = 'normal';

    case HIGH = 'high';

    case URGENT = 'urgent';

    public function label(): string
    {
        return __('tickets.priority.'.$this->value);
    }
}
