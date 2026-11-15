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
    | "database" keeps the state of each flag in the toggles table, which is
    | what Settings → Features changes. A flag with no row there falls back
    | to the value in "flags" below.
    |
    */

    'driver' => env('TOGGLE_DRIVER', 'database'),

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
    | Which major features are available in this installation. A disabled
    | feature has its menu items hidden and its routes blocked.
    |
    | These are only the starting values. Admins change them under
    | Settings → Features, and a value saved there wins over the env var.
    | The migration that creates the toggles table copies the flags enabled
    | here into it, so the env vars can be removed afterwards. Keep the list
    | in step with App\Support\FeatureFlags.
    |
    */

    'flags' => [
        'sub-organizations' => env('TOGGLE_SUB_ORGANIZATIONS', false),
        'emails' => env('TOGGLE_EMAILS', false),
        'shared-apps' => env('TOGGLE_SHARED_APPS', false),
        'app-backups' => env('TOGGLE_APP_BACKUPS', false),
    ],

    'database_flags' => [
        //
    ],

];
