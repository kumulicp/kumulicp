<?php

namespace App\Console\Calls;

use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Organization;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization as OrganizationFacade;

class ExpiredSubscriptionsCheck
{
    public function __invoke()
    {
        $organizations = Organization::where('status', '!=', 'deactivated')->get();

        foreach ($organizations as $organization) {
            OrganizationFacade::setOrganization($organization);
            // Checks if subscription has finished grace period
            if (is_null($organization->plan) || ($organization->plan->payment_enabled && Billing::status() === 'ended')) {
                $task = Action::execute(new DeactivateOrganization($organization));
            } elseif ($organization->app_instances()->where('trial_ends_at', '<', now())->count() > 0) {
                $organization->app_instances()->where('trial_ends_at', '<', now())->update(['trial_ends_at' => null]);
                $subscription = (new SubscriptionService($organization))->all();
                $task = Action::execute(new SubscriptionUpdate($organization, $subscription), background: true);
            }
        }
    }
}
