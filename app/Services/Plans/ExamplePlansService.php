<?php

namespace App\Services\Plans;

use App\Plan;
use Illuminate\Support\Collection;

/**
 * Creates the example Packaged plan tiers offered on a fresh install.
 *
 * The tiers are only a starting point for administrators: they are hidden, carry
 * no prices or Stripe price ids, and are not attached to any app (no apps exist
 * yet at install time). See docs/plan-types-and-limits.md for how the values
 * were chosen.
 */
class ExamplePlansService
{
    /**
     * Limits per tier. Keys line up with the plan settings the admin form edits.
     */
    public const TIERS = [
        'starter' => [
            'standard_max' => 10,
            'basic_max' => 50,
            'application_max' => 3,
            'email_max' => 5,
            'email_storage' => 1,
            'domain_max' => 1,
        ],
        'standard' => [
            'standard_max' => 50,
            'basic_max' => 250,
            'application_max' => 6,
            'email_max' => 25,
            'email_storage' => 2,
            'domain_max' => 3,
        ],
        'plus' => [
            'standard_max' => 200,
            'basic_max' => 1000,
            'application_max' => 12,
            'email_max' => 100,
            'email_storage' => 5,
            'domain_max' => 10,
        ],
    ];

    // Limits shared by every tier
    private const STANDARD_STORAGE = 5;

    private const BASIC_STORAGE = 1;

    private const BASIC_BUNDLE_SIZE = 5;

    private const STORAGE_BLOCK = 10;

    private const STORAGE_MAX_BLOCKS = 10;

    /**
     * Create the example tiers unless the installation already has plans of its own.
     *
     * @return Collection<int, Plan>
     */
    public function create(): Collection
    {
        if (Plan::where('archive', 0)->exists()) {
            return collect();
        }

        $display_order = (int) Plan::max('display_order');
        $plans = collect();

        foreach (self::TIERS as $tier => $limits) {
            $plan = new Plan;
            $plan->name = __("setup.example_plans.{$tier}.name");
            $plan->description = __("setup.example_plans.{$tier}.description");
            $plan->type = 'package';
            $plan->org_type = 'business';
            $plan->status = 'hidden';
            $plan->display_order = ++$display_order;
            $plan->payment_enabled = false;
            $plan->domain_enabled = true;
            $plan->domain_max = $limits['domain_max'];
            $plan->email_enabled = false;
            $plan->app_plans = [];
            $plan->features = $this->features($limits);
            $plan->settings = $this->settings($limits);
            $plan->save();

            $plans->push($plan);
        }

        return $plans;
    }

    private function settings(array $limits): array
    {
        return [
            'suborganizations' => ['enabled' => false],
            'base' => ['minimal_label' => null],
            'standard' => [
                'max' => $limits['standard_max'],
                'storage' => self::STANDARD_STORAGE,
            ],
            'basic' => [
                'name' => __('setup.example_plans.basic_name'),
                'amount' => self::BASIC_BUNDLE_SIZE,
                'max' => $limits['basic_max'],
                'storage' => self::BASIC_STORAGE,
            ],
            'storage' => [
                'amount' => self::STORAGE_BLOCK,
                'max' => self::STORAGE_MAX_BLOCKS,
            ],
            'application' => ['max' => $limits['application_max']],
            'email' => [
                'max' => $limits['email_max'],
                'storage' => $limits['email_storage'],
            ],
            'domains' => [
                'connect' => true,
                'register' => false,
                'transfer' => false,
            ],
        ];
    }

    private function features(array $limits): array
    {
        return [
            [
                'name' => __('setup.example_plans.features.users'),
                'description' => __('setup.example_plans.features.users_description', ['count' => $limits['standard_max']]),
            ],
            [
                'name' => __('setup.example_plans.features.apps'),
                'description' => __('setup.example_plans.features.apps_description', ['count' => $limits['application_max']]),
            ],
            [
                'name' => __('setup.example_plans.features.storage'),
                'description' => __('setup.example_plans.features.storage_description', ['count' => self::STANDARD_STORAGE]),
            ],
        ];
    }
}
