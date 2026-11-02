<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket reference prefix
    |--------------------------------------------------------------------------
    | Shown before the sequential number, e.g. KCP-1042.
    */
    'prefix' => env('TICKETS_PREFIX', 'KCP'),

    /*
    |--------------------------------------------------------------------------
    | Message editing
    |--------------------------------------------------------------------------
    | An author may edit their own message until a staff member replies after
    | it, and for at most this many minutes.
    */
    'edit_window_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Legacy support address
    |--------------------------------------------------------------------------
    | While tickets roll out, new tickets are also mailed to the `support_email`
    | server setting. Set TICKETS_COPY_SUPPORT_EMAIL=false once the inbox is
    | in use. This copy will be removed in a later release.
    */
    'copy_support_email' => env('TICKETS_COPY_SUPPORT_EMAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_subject_length' => 150,
    'max_body_characters' => 50000,

    // "attempts,minutes" for the throttle middleware.
    'throttle' => [
        'create' => '10,60',
        'reply' => '30,60',
    ],
];
