<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Database\Eloquent\Builder where(string $column, mixed $operator = null, mixed $value = null)
 * @method static \App\AppInstance|null appByName(string $app)
 */
class Organization extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'organizations';
    }
}
