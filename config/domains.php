<?php

return [
    'default' => env('REGISTRAR_DRIVER'),

    'registrars' => [
        'namecheap' => [
            'url' => env('NC_URL'),
            'api_user' => env('NC_API_USER'),
            'api_key' => env('NC_API_KEY'),
            'username' => env('NC_USERNAME'),
            'client_ip' => env('NC_CLIENT_IP'),
        ],
    ],
];
