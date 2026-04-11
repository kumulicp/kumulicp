<?php

namespace App\Services\Organization;

use App\Organization;
use App\Services\SubscriptionService;
use App\Support\Facades\Application;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;

class JointSubscriptionService
{
    public $organization;

    public $subscriptions = [];

    public $included_subscriptions = [];

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization ?? OrganizationFacade::account();
    }

    public function allSubscriptions()
    {
        $suborgs = $this->organization->suborganizations()->where('settings->include_in_parent_invoice', true)->get();
        foreach ($suborgs as $suborg) {
            $this->subscriptions[] = (new SubscriptionService($suborg))->all();
        }

        return $this->subscriptions;
    }

    public function includedSubscriptions()
    {
        $this->included_subscriptions[] = Subscription::all();

        $suborgs = $this->organization->suborganizations()->where('settings->include_in_parent_invoice', true)->get();
        foreach ($suborgs as $suborg) {
            $this->included_subscriptions[] = (new SubscriptionService($suborg))->all();
        }

        return $this->included_subscriptions;
    }

    public function paidSubscriptions()
    {
        $subscriptions = [];

        foreach ($this->includedSubscriptions() as $subscription) {
            $subscriptions = array_merge($subscriptions, $subscription->paidSubscriptions());
        }

        return $subscriptions;
    }

    public function countEntity(string $entity)
    {
        $count = 0;
        foreach ($this->includedSubscriptions() as $subscription) {
            $count += $subscription->countEntity();
        }

        return $count;
    }

    public function domainsEnabled()
    {
        foreach ($this->allSubscriptions() as $subscription) {
            if ($subscription->domainsEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function emailEnabled()
    {
        foreach ($this->allSubscriptions() as $subscription) {
            if ($subscription->emailEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function totalPrice()
    {
        $total = 0;

        foreach ($this->allSubscriptions() as $subscription) {
            $total += $subscription->totalPrice();
        }

        return $total;
    }

    public function isAppLimitReached(Application $app): bool
    {
        foreach ($this->allSubscriptions() as $subscription) {
            if ($subscription->isMaxApps($app)) {
                return true;
            }
        }

        return false;
    }
}
