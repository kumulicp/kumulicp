<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "config", "database"
    |
    */

    'driver' => env('TOGGLE_DRIVER', 'config'),

    /*
    |--------------------------------------------------------------------------
    | Default Value Behavior
    |--------------------------------------------------------------------------
    |
    | Supported: "false", "true", "exception"
    |
    */

    'default' => env('TOGGLE_DEFAULT', 'false'),

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => env('TOGGLE_CACHE_ENABLED', true),
        'store' => env('TOGGLE_CACHE_STORE', null),
        'ttl' => env('TOGGLE_CACHE_TTL', 3600),
        'prefix' => 'toggle:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table
    |--------------------------------------------------------------------------
    */

    'table' => 'toggles',

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Controls which major features are available in this installation.
    | Set the corresponding env var to false to disable a feature entirely,
    | which will hide its menu items and block its routes.
    |
    */

    'flags' => [
        'sub-organizations' => env('TOGGLE_SUB_ORGANIZATIONS', true),
        'emails' => env('TOGGLE_EMAILS', true),
        'shared-apps' => env('TOGGLE_SHARED_APPS', true),
    ],

    'database_flags' => [
        //
    ],

];
