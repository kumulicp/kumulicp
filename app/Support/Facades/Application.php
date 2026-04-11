<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

class Application extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'applications';
    }
}
