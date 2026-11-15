<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stuck task alerts
    |--------------------------------------------------------------------------
    |
    | A task that has stopped getting anywhere logs an error, once for each
    | reason (so it is emailed once, and shows on the dashboard and the logs
    | page). Times are in minutes.
    |
    */

    'alerts' => [
        // Blocked by a failed or cancelled task for this long
        'blocked_after' => env('TASK_ALERT_BLOCKED_MINUTES', 60),

        // In progress, not being run, and nothing has changed for this long. Not
        // for tasks with a deadline of their own: they are failed at it.
        'stalled_after' => env('TASK_ALERT_STALLED_MINUTES', 360),

        // Never started, and has been waiting for this long
        'waiting_after' => env('TASK_ALERT_WAITING_MINUTES', 1440),

        // Queued, but its job never ran, this many times: the queue workers are
        // probably down
        'requeued_times' => env('TASK_ALERT_REQUEUED_TIMES', 3),
    ],

];
