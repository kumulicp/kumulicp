<?php

namespace App\Services\Billing;

use App\Organization;
use App\Services\Organization\JointSubscriptionService;
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
            lines: $this->lines($subscription),
            discountId: $organization->discount_id ?: null,
            trialDays: $firstSubscription ? (int) config('billing.trial_days', 30) : 0,
            firstSubscription: $firstSubscription,
        );
    }

    /**
     * One line per external price id, quantities added together across plans.
     *
     * @return Collection<int, SubscriptionLine>
     */
    private function lines(object $subscription): Collection
    {
        /** @var array<string, SubscriptionLine> $lines */
        $lines = [];

        foreach ($subscription->paidSubscriptions() as $plan) {
            foreach ($plan->pricingOptions() as $component => $info) {
                $externalId = Arr::get($info, 'price_id');
                $quantity = (int) Arr::get($info, 'quantity', 0);

                if (! $externalId || $quantity <= 0) {
                    continue;
                }

                $lines[$externalId] = isset($lines[$externalId])
                    ? $lines[$externalId]->withQuantity($lines[$externalId]->quantity + $quantity)
                    : new SubscriptionLine($externalId, $quantity, (string) $component, Arr::get($info, 'price'));
            }
        }

        return collect(array_values($lines));
    }
}
