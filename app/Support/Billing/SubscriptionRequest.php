<?php

namespace App\Support\Billing;

use App\Organization;
use Illuminate\Support\Collection;

/**
 * What a billing driver is asked to make true: this organization should be
 * subscribed to exactly these lines. Built once for every driver by
 * SubscriptionRequestBuilder, so a driver never reads plans or settings itself.
 */
final class SubscriptionRequest
{
    /**
     * @param  Organization  $organization  the organization that is billed; the parent when a child is invoiced with it
     * @param  Collection<int, SubscriptionLine>  $lines
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly Collection $lines,
        public readonly ?string $discountId = null,
        public readonly int $trialDays = 0,
        public readonly bool $firstSubscription = false,
    ) {}
}
