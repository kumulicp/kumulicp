<?php

namespace App\Support\Facades;

use App\Enums\TenancyMode;
use App\Organization;
use App\Services\TenancyService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TenancyMode mode()
 * @method static bool isSingle()
 * @method static bool isMulti()
 * @method static bool isSingleOrganization(Organization $organization)
 * @method static void set(TenancyMode $mode)
 * @method static void switchTo(TenancyMode $mode)
 * @method static list<string> whySingleIsNotPossible()
 * @method static TenancyMode installerDefault()
 * @method static TenancyMode|null installerMode()
 * @method static Organization|null organization()
 *
 * @see TenancyService
 */
class Tenancy extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'tenancy';
    }
}
