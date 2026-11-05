<?php

use App\Console\Calls\ExpiredSubscriptionsCheck;
use App\Organization;
use App\Plan;
use App\Support\Facades\Billing;
use App\Task;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Billing\RecordingBillingGateway;

// Regression: the check compared the display label returned by
// Billing::status() to the machine key 'ended', which never matched, so
// organizations whose paid subscription had ended were never deactivated.
it('deactivates organizations whose paid subscription has ended', function () {
    Notification::fake();
    Mail::fake();

    RecordingBillingGateway::reset();
    Billing::register('recording', RecordingBillingGateway::class);
    config(['billing.default' => 'recording']);

    $plan = Plan::factory()->create(['payment_enabled' => true]);
    $ended = Organization::factory()->create(['plan_id' => $plan->id]);
    $current = Organization::factory()->create(['plan_id' => $plan->id]);

    RecordingBillingGateway::$ended = [$ended->id];

    (new ExpiredSubscriptionsCheck)();

    $deactivated = Task::where('action_slug', 'deactivate_organization')->pluck('organization_id')->all();

    expect($deactivated)->toBe([$ended->id]);
});
