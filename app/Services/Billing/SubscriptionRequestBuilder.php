<?php

namespace App\Services\Billing;

use App\Organization;
use App\Services\Organization\JointSubscriptionService;
use App\Support\Billing\PlanPriceBackfill;
use App\Support\Billing\SubscriptionLine;
use App\Support\Billing\SubscriptionRequest;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Works out what an organization should be billed for, from its plans, without
 * knowing anything about the billing engine that will bill it.
 */
class SubscriptionRequestBuilder
{
    public function build(Organization $organization): SubscriptionRequest
    {
        // A child organization invoiced with its parent is billed on the parent's subscription
        if ($organization->setting('include_in_parent_invoice') === true && ! is_null($organization->parent_organization)) {
            $organization = $organization->parent_organization;
            OrganizationFacade::setOrganization($organization);
            $subscription = new JointSubscriptionService($organization);
        } elseif ($organization->suborganizations()->count() > 0) {
            $subscription = new JointSubscriptionService($organization);
        } else {
            $subscription = Subscription::all();
        }

        $firstSubscription = $organization->setting('step') === 1;

        return new SubscriptionRequest(
            organization: $organization,
            lines: $this->linesFor($subscription),
            discountId: $organization->discount_id ?: null,
            trialDays: $firstSubscription ? (int) config('billing.trial_days', 30) : 0,
            firstSubscription: $firstSubscription,
        );
    }

    /**
     * One line per external price id, quantities added together across plans.
     *
     * An app instance's plan lists its priced optional features together under a
     * "features" key (name => price_id, quantity, ...) instead of one entry per
     * item, so those are unpacked into lines of their own, as "feature.{name}".
     *
     * @param  object  $subscription  anything with paidSubscriptions(), each plan having pricingOptions()
     * @return Collection<int, SubscriptionLine>
     */
    public function linesFor(object $subscription): Collection
    {
        /** @var array<string, SubscriptionLine> $lines */
        $lines = [];

        foreach ($subscription->paidSubscriptions() as $plan) {
            foreach ($plan->pricingOptions() as $component => $info) {
                if ($component === 'features') {
                    foreach (is_array($info) ? $info : [] as $name => $feature) {
                        $this->addLine($lines, PlanPriceBackfill::featureComponent((string) $name), $feature);
                    }

                    continue;
                }

                $this->addLine($lines, (string) $component, $info);
            }
        }

        return collect(array_values($lines));
    }

    /**
     * @param  array<string, SubscriptionLine>  $lines
     */
    private function addLine(array &$lines, string $component, mixed $info): void
    {
        $externalId = Arr::get($info, 'price_id');
        $quantity = (int) Arr::get($info, 'quantity', 0);

        if (! $externalId || $quantity <= 0) {
            return;
        }

        $lines[$externalId] = isset($lines[$externalId])
            ? $lines[$externalId]->withQuantity($lines[$externalId]->quantity + $quantity)
            : new SubscriptionLine($externalId, $quantity, $component, Arr::get($info, 'price'));
    }
}
