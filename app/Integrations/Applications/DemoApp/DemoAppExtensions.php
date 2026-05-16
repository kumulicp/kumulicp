<?php

namespace App\Integrations\Applications\DemoApp;

use App\AppInstance;
use App\Services\AdditionalStorageService;
use App\Support\Facades\Subscription;

class DemoAppExtensions
{
    public function __construct(private AppInstance $app_instance) {}

    public function groups($attributes)
    {
        $plan = $this->app_instance->plan;

        if (! $plan->setting('storage.amount') || $plan->setting('storage.amount') <= 0) {
            return [];
        }

        $subscription_plan = Subscription::app_instance($this->app_instance);

        if (! $subscription_plan) {
            return [];
        }

        $name = array_key_exists('name', $attributes) ? $attributes['name'] : 'new';

        $additional_storage = new AdditionalStorageService(
            $this->app_instance->organization,
            'group',
            $name,
            $this->app_instance
        );

        if ($subscription_plan->isMax('storage') && ! $additional_storage->quantity()) {
            return [
                [
                    'label' => __('messages.extensions.demo_app.add_group_storage'),
                    'input' => __('messages.extensions.demo_app.max_reached'),
                    'id' => 'demo_group_storage',
                ],
            ];
        }

        $quantity = $additional_storage->quantity();
        $options = $additional_storage->additionalStorageOptions();

        return [
            [
                'label' => __('messages.extensions.demo_app.add_group_storage'),
                'input' => 'va-checkbox',
                'id' => 'demo_group_storage',
                'value' => $quantity > 0,
                'warning' => __('messages.extensions.demo_app.subscription_affected'),
            ],
            [
                'label' => __('messages.extensions.demo_app.group_storage_amount'),
                'input' => 'va-select',
                'id' => 'demo_additional_storage',
                'value' => $quantity > 0 ? $quantity : 1,
                'options' => $options,
                'conditional' => 'demo_group_storage',
                'warning' => __('messages.extensions.demo_app.subscription_affected'),
            ],
        ];
    }
}
