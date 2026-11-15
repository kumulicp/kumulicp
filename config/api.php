<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed on /api/v1 routes (the "api" rate limiter,
    | defined in AppServiceProvider). Callers are keyed by authenticated
    | identity, falling back to IP address for anonymous requests.
    |
    */

    'rate_limit' => (int) env('API_RATE_LIMIT', 60),

];
