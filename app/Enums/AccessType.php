<?php

namespace App\Enums;

use App\Support\Facades\Subscription;

enum AccessType: string
{
    case STANDARD = 'standard';

    case BASIC = 'basic';

    case MINIMAL = 'minimal';

    case NONE = 'none';

    public function label()
    {
        $plan = Subscription::base();

        return match ($this) {
            AccessType::STANDARD => $plan->accessTypeName(AccessType::STANDARD),
            AccessType::BASIC => $plan->accessTypeName(AccessType::BASIC),
            AccessType::MINIMAL => $plan->accessTypeName(AccessType::MINIMAL),
            AccessType::NONE => $plan->accessTypeName(AccessType::NONE),
        };
    }
}
