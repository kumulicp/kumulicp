<?php

namespace App\Services;

use App\Enums\TenancyMode;
use App\Organization;
use App\Support\Facades\Settings;

class TenancyService
{
    private const SETTING = 'tenancy_mode';

    /**
     * The mode this installation runs in. Installs that predate the setting
     * (and anything unreadable) are multi-tenant, which is how they behaved.
     */
    public function mode(): TenancyMode
    {
        return TenancyMode::tryFrom((string) Settings::get(self::SETTING, '')) ?? TenancyMode::Multi;
    }

    public function isSingle(): bool
    {
        return $this->mode() === TenancyMode::Single;
    }

    public function isMulti(): bool
    {
        return $this->mode() === TenancyMode::Multi;
    }

    public function set(TenancyMode $mode): void
    {
        Settings::update(self::SETTING, $mode->value);
    }

    /**
     * What the installer should pre-select: the TENANCY_MODE env value if it
     * is valid, otherwise multi (the behavior before modes existed).
     */
    public function installerDefault(): TenancyMode
    {
        return $this->installerMode() ?? TenancyMode::Multi;
    }

    /**
     * The mode the operator fixed ahead of time through TENANCY_MODE, if any.
     */
    public function installerMode(): ?TenancyMode
    {
        return TenancyMode::tryFrom((string) config('tenancy.mode'));
    }

    /**
     * The one organization in a single-organization install: the same
     * `superaccount` the installer creates and background actions already use.
     */
    public function organization(): ?Organization
    {
        return Organization::where('type', 'superaccount')->first();
    }
}
