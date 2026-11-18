<?php

return [
    'default' => env('BILLING_DRIVER'),
    'include_taxes' => env('BILLING_TAXES', true),
    // Days of free trial on an organization's first paid subscription
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),
];
