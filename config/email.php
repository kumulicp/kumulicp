<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Undo window
    |--------------------------------------------------------------------------
    |
    | How many days a user can switch back after an organization address replaced
    | the email they signed in with. It does not repair accounts an app already
    | made for the new address.
    |
    */

    'undo_days' => (int) env('EMAIL_UNDO_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Recovery email takeover delay
    |--------------------------------------------------------------------------
    |
    | Hours before a new recovery email replaces a working one for password
    | resets. Until then resets keep going to the old one, which is also told.
    |
    */

    'recovery_delay_hours' => (int) env('EMAIL_RECOVERY_DELAY_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Confirmation link lifetime
    |--------------------------------------------------------------------------
    */

    'recovery_confirm_hours' => (int) env('EMAIL_RECOVERY_CONFIRM_HOURS', 24),

];
