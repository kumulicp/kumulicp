<?php

namespace App\Enums;

enum TicketType: string
{
    case QUESTION = 'question';

    case BUG = 'bug';

    case FEATURE = 'feature';

    public function label(): string
    {
        return __('tickets.type.'.$this->value);
    }
}
