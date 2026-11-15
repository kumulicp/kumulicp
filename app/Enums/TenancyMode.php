<?php

namespace App\Enums;

/**
 * How this installation is used: one organization running its own apps, or a
 * hosting provider serving many organizations. Chosen at install time.
 */
enum TenancyMode: string
{
    // One organization; no plans, subscriptions, billing or self-registration
    case Single = 'single';

    // Many organizations, each subscribed to a plan
    case Multi = 'multi';

    public function label(): string
    {
        return __("setup.tenancy.modes.{$this->value}.label");
    }

    public function description(): string
    {
        return __("setup.tenancy.modes.{$this->value}.description");
    }
}
