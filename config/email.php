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

    /*
    |--------------------------------------------------------------------------
    | Aliases per mailbox
    |--------------------------------------------------------------------------
    |
    | The most aliases one mailbox can have. Empty means no limit.
    |
    */

    'max_aliases' => env('EMAIL_MAX_ALIASES') === null || env('EMAIL_MAX_ALIASES') === '' ? null : (int) env('EMAIL_MAX_ALIASES'),

    /*
    |--------------------------------------------------------------------------
    | Forwarders
    |--------------------------------------------------------------------------
    |
    | The most destinations one forwarder can have, and how many hours the link
    | sent to an outside destination works before it has to be sent again.
    |
    */

    'max_forwarder_destinations' => (int) env('EMAIL_MAX_FORWARDER_DESTINATIONS', 10),

    'forwarder_confirm_hours' => (int) env('EMAIL_FORWARDER_CONFIRM_HOURS', 72),

];
