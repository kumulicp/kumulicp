<?php

return [
    'extension' => 'PHP Settings: Extension Loaded :extension',
    'function' => 'PHP Settings: Function Enabled: :function',
    'stripe_connection' => 'Stripe Connection',
    'plan' => [
        'name' => 'The Ultimate Plan',
        'description' => 'Unlimited apps, storage, and traffic for free!',
    ],
    'example_plans' => [
        'basic_name' => 'Volunteer',
        'starter' => [
            'name' => 'Starter',
            'description' => 'Example tier for small teams getting started. Edit or delete it.',
        ],
        'standard' => [
            'name' => 'Standard',
            'description' => 'Example tier for growing organizations. Edit or delete it.',
        ],
        'plus' => [
            'name' => 'Plus',
            'description' => 'Example tier for large organizations. Edit or delete it.',
        ],
        'features' => [
            'users' => 'Users',
            'users_description' => 'Up to :count users',
            'apps' => 'Apps',
            'apps_description' => 'Up to :count apps',
            'storage' => 'Storage',
            'storage_description' => ':count GB per user',
        ],
    ],
    'complete' =>'The control panel has been sucessfully setup',
    'first_org' => 'Super admin account',
];
