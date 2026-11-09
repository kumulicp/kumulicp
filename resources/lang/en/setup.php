<?php

return [
    'extension' => 'PHP Settings: Extension Loaded :extension',
    'function' => 'PHP Settings: Function Enabled: :function',
    'stripe_connection' => 'Stripe Connection',
    'plan' => [
        'name' => 'The Ultimate Plan',
        'description' => 'Unlimited apps, storage, and traffic for free!',
    ],
    'complete' => 'The control panel has been sucessfully setup',
    'first_org' => 'Super admin account',
    'tenancy' => [
        'modes' => [
            'single' => [
                'label' => 'Single organization',
                'description' => 'One organization runs its own apps. There are no plans, subscriptions, billing or self-registration.',
            ],
            'multi' => [
                'label' => 'Multi-tenant',
                'description' => 'A hosting provider serves many organizations, each subscribed to a plan.',
            ],
        ],
    ],
];
