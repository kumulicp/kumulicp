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
            $billing_managers_notified = false;
            $invoice_num = 0;

            if (! Billing::isBillable()) {
                return;
            }

            Billing::sendInvoices();
        }
    }
}
