<?php

namespace App\Console\Calls;

use App\Support\Facades\Billing;
use App\Support\Facades\Organization;

class NotifyBillingManagers
{
    public function __invoke()
    {
        $organizations = \App\Organization::with('subscriptions')
            ->whereRelation('subscriptions', 'stripe_status', '!=', 'canceled')
            ->whereRelation('subscriptions', 'invoice_sent_at', '<', now()->subDays(20))
            ->where('status', '!=', 'deactivated')
            ->get();

        foreach ($organizations as $organization) {
            Organization::setOrganization($organization);

            // Skip this organization only; `return` here used to abort the
            // whole run, so one non-billable org starved every org after it.
            if (! Billing::isBillable()) {
                continue;
            }

            Billing::sendInvoices();
        }
    }
}
