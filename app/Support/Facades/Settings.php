<?php

namespace App\Support\Facades;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $fallback = '')
 * @method static void update(string $key, mixed $value = null)
 * @method static void remove(string $key)
 *
 * @see SettingsService
 */
class Settings extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'settings';
    }
}
