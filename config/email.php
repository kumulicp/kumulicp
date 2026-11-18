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

];
