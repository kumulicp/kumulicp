<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'matrix' => [
        'homeserver' => env('MATRIX_HOMESERVER'),
        'access_token' => env('MATRIX_ACCESS_TOKEN'),
        'room_id' => env('MATRIX_ROOM_ID'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    'plugin_registry' => [
        'url' => env('PLUGIN_REGISTRY_URL'),
    ],

    'helm' => [
        'binary_path' => env('HELM_BINARY_PATH', 'helm'),
    ],

    'kubectl' => [
        'binary_path' => env('KUBECTL_BINARY_PATH', 'kubectl'),
    ],

    // Local-dev only: see Integration::devIngressResolve(). Set to
    // host.docker.internal when running app instances against a local
    // cluster (e.g. k3s) whose ingress is only reachable via the host.
    'dev_ingress_gateway' => env('DEV_INGRESS_GATEWAY'),
];
