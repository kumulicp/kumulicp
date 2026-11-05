<?php

use App\Console\Calls\NotifyBillingManagers;
use App\Organization;
use App\Support\Facades\Billing;
use Illuminate\Support\Facades\DB;
use Tests\Support\Billing\RecordingBillingGateway;

/**
 * Insert the subscription row NotifyBillingManagers selects on: not cancelled,
 * and last invoiced more than 20 days ago.
 */
function addDueSubscription(Organization $organization): void
{
    DB::table('subscriptions')->insert([
        'organization_id' => $organization->id,
        'name' => 'default',
        'type' => 'default',
        'stripe_id' => 'sub_fake_'.$organization->id,
        'stripe_status' => 'active',
        'invoice_sent_at' => now()->subDays(30),
        'created_at' => now()->subDays(60),
        'updated_at' => now()->subDays(30),
    ]);
}

it('keeps notifying later organizations when an earlier one is not billable', function () {
    RecordingBillingGateway::reset();
    Billing::register('recording', RecordingBillingGateway::class);
    config(['billing.default' => 'recording']);

    // The query has no ORDER BY, so surround the billable organization with
    // non-billable ones: whichever way the rows come back, at least one
    // non-billable organization is processed before it.
    $before = Organization::factory()->create();
    $billable = Organization::factory()->create();
    $after = Organization::factory()->create();

    foreach ([$before, $billable, $after] as $organization) {
        addDueSubscription($organization);
    }

    RecordingBillingGateway::$billable = [$billable->id];

    (new NotifyBillingManagers)();

    expect(RecordingBillingGateway::$invoiced)->toBe([$billable->id]);
});

it('does not send invoices for organizations that are not billable', function () {
    RecordingBillingGateway::reset();
    Billing::register('recording', RecordingBillingGateway::class);
    config(['billing.default' => 'recording']);

    $organization = Organization::factory()->create();
    addDueSubscription($organization);

    (new NotifyBillingManagers)();

    expect(RecordingBillingGateway::$invoiced)->toBe([]);
});
