<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installation Mode
    |--------------------------------------------------------------------------
    |
    | Read only by the installer. "single" runs the control panel for one
    | organization (no plans, subscriptions or billing); "multi" is the
    | hosting-provider setup. Leave empty to choose on the setup screen.
    |
    | After installation the mode is stored in the `tenancy_mode` server
    | setting and this value is ignored. To change it later, use the
    | `kumulicp:tenancy` artisan command.
    |
    | Installs that predate this setting have no stored mode and are "multi".
    |
    */

    'mode' => env('TENANCY_MODE'),

];
