<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

class SecretStore extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'secret_store';
    }
}
