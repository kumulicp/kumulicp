<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array requiredDNSRecords(\App\OrgDomain $domain)
 */
class Email extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'email';
    }
}
