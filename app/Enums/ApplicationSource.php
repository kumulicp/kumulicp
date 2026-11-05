<?php

namespace App\Enums;

/**
 * Which channel decides how an application is deployed.
 */
enum ApplicationSource: string
{
    // Has a hand-written profile registered in code (built-in or from a plugin module)
    case Profile = 'profile';

    // Added by an admin in the UI, deployed with the generic chart
    case Generic = 'generic';

    public function label(): string
    {
        return __("admin.applications.sources.{$this->value}");
    }
}
