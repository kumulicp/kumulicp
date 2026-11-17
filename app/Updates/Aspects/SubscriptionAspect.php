<?php

namespace App\Updates\Aspects;

use App\AppInstance;
use App\Updates\AspectInputs;
use App\Updates\Fingerprint;

/**
 * What the organization is billed for the app: whether the plan charges, the
 * prices of its components and of its features, and what a price is charged
 * for (the users or the gigabytes that make up a block).
 */
class SubscriptionAspect extends Aspect
{
    public function key(): string
    {
        return 'subscription';
    }

    public function label(): string
    {
        return __('admin.update_aspects.subscription');
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        $plan = $app_instance->plan;

        $inputs = [
            'plan' => $plan ? ['id' => $plan->id, 'payment_enabled' => $plan->payment_enabled] : null,
            'settings' => AspectInputs::planSettings($app_instance, $this->key()),
            'features' => AspectInputs::featurePricing($plan),
        ];

        // Prices live in plan_prices rather than the plan's settings. Left out
        // when there are none, so a plan without prices keeps its fingerprint.
        if ($prices = AspectInputs::planPrices($plan)) {
            $inputs['prices'] = $prices;
        }

        return Fingerprint::of($inputs);
    }
}
