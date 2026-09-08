<?php

return [
    // Only used to seed the initial default secret store on install. Which
    // store a Server actually uses is resolved per-server from the
    // secret_stores table (servers.secret_store_id), not from this config.
    'default_driver' => env('SECRET_STORE_DRIVER', 'database'),
];
