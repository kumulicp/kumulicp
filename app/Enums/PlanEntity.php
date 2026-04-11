<?php

namespace App\Enums;

enum PlanEntity: string
{
    case BASE = 'base';

    case STANDARD_USER = 'standard';

    case BASIC_USER = 'basic';

    case APPLICATION = 'application';

    case EMAIL = 'email';

    case ADDITIONAL_STORAGE = 'storage';

    case FEATURE = 'feature';
}
