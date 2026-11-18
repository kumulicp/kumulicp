<?php

namespace App\Services;

use App\Enums\TenancyMode;
use App\Organization;
use App\Support\Facades\FastCache;
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

    /**
     * Whether this is the one organization of a single-organization install.
     * Test organizations made by version tests are not.
     */
    public function isSingleOrganization(Organization $organization): bool
    {
        return $this->isSingle() && $organization->type === 'superaccount';
    }

    public function set(TenancyMode $mode): void
    {
        Settings::update(self::SETTING, $mode->value);
    }

    /**
     * Change the mode of an installed control panel. What is cached per organization was
     * built for the old mode (the menus, the plans), so it is cleared with the change.
     */
    public function switchTo(TenancyMode $mode): void
    {
        $this->set($mode);

        FastCache::clearAll();
    }

    /**
     * What stands in the way of this install being a single-organization one, in words an
     * admin can act on. Empty when nothing does.
     *
     * @return list<string>
     */
    public function whySingleIsNotPossible(): array
    {
        $reasons = [];

        // Version tests make organizations of their own, and the shared organization only hosts shared apps
        $organizations = Organization::notTest()->where('type', '!=', 'shared')->get();

        if ($organizations->count() !== 1) {
            $reasons[] = "There must be exactly one organization, and there are {$organizations->count()}"
                .($organizations->isEmpty() ? '.' : ': '.$organizations->pluck('name')->implode(', ').'. Delete the others first.');
        } elseif ($organizations->first()->type !== 'superaccount') {
            $reasons[] = "The organization \"{$organizations->first()->name}\" is not the administrator account made at install, so it can't be the one organization.";
        }

        if (Organization::where('type', 'shared')->whereHas('app_instances')->exists()) {
            $reasons[] = 'Shared apps are running, and a single-organization install has none. Remove them first.';
        }

        return $reasons;
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
