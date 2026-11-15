<?php

use App\Enums\TaskStatus;
use App\Organization;
use App\Plan;
use App\Support\Billing\BillingSyncState;
use App\Support\Facades\AccountManager;
use App\Task;
use App\User;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo app and acts as the demo user.
// The test billing driver is a fake that never reports a subscription, like a provider whose
// subscription hasn't been created yet.

function subscriptionTask(Organization $organization, TaskStatus $status, string $error = ''): Task
{
    $task = new Task;
    $task->organization_id = $organization->id;
    $task->action_slug = 'subscription_update';
    $task->action_group = 'organizations';
    $task->task_group = rand();
    $task->status = $status;
    $task->error_message = $error;
    $task->description = 'Updating subscription';
    $task->save();

    return $task;
}

// A real request gets fresh scoped services and a freshly loaded user and organization (see PlanMenuTest)
function freshRequestFor($test): void
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    $test->actingAs(User::findOrFail($test->user->id));
}

beforeEach(function () {
    config(['billing.default' => 'fake']);
    $this->organization = Organization::find(1);
});

it('shows the billing pages for a payment enabled plan before the provider has a subscription', function () {
    $plan = Plan::factory()->create(['org_type' => $this->organization->type, 'payment_enabled' => true]);
    $this->organization->forceFill(['plan_id' => $plan->id])->save();

    freshRequestFor($this);
    expect(Gate::inspect('has-billing-account')->allowed())->toBeTrue();
});

it('hides the billing pages for an organization that has never had anything billable', function () {
    $plan = Plan::factory()->create(['org_type' => $this->organization->type, 'payment_enabled' => false]);
    $this->organization->forceFill(['plan_id' => $plan->id])->save();

    freshRequestFor($this);
    expect(Gate::inspect('has-billing-account')->allowed())->toBeFalse();
});

it('keeps the billing pages once the organization has had a subscription, even on a free plan', function () {
    $plan = Plan::factory()->create(['org_type' => $this->organization->type, 'payment_enabled' => false]);
    $this->organization->forceFill(['plan_id' => $plan->id])->save();

    $this->organization->subscriptions()->create([
        'name' => 'default', 'type' => 'default', 'stripe_id' => 'sub_history', 'stripe_status' => 'canceled', 'ends_at' => now()->subDay(),
    ]);
    config(['billing.default' => 'stripe']);

    freshRequestFor($this);
    expect(Gate::inspect('has-billing-account')->allowed())->toBeTrue();
});

it('reports where the queued billing update stands', function () {
    expect(BillingSyncState::for($this->organization))->toBe(['pending' => false, 'failed' => false]);

    subscriptionTask($this->organization, TaskStatus::QUEUED);
    expect(BillingSyncState::for($this->organization)['pending'])->toBeTrue();

    subscriptionTask($this->organization, TaskStatus::COMPLETE);
    expect(BillingSyncState::for($this->organization))->toBe(['pending' => false, 'failed' => false]);

    subscriptionTask($this->organization, TaskStatus::FAILED, "No such price: 'dfg'");
    expect(BillingSyncState::for($this->organization))->toBe(['pending' => false, 'failed' => true]);
});

it('never shares the reason a billing update failed, not even with admins', function () {
    subscriptionTask($this->organization, TaskStatus::FAILED, "No such coupon: 'DISCOUNT1'");

    freshRequestFor($this);
    $page = $this->get('/');
    $props = $page->viewData('page')['props'];

    expect($props['billing_sync'])->toBe(['pending' => false, 'failed' => true]);
    expect($page->getContent())->not->toContain('DISCOUNT1')->not->toContain('No such coupon');

    // A customer without admin access gets the same
    AccountManager::users()->find('demo')->permissions()->removeControlPanelAdminAccess();
    freshRequestFor($this);
    $page = $this->get('/');

    expect($page->viewData('page')['props']['billing_sync'])->toBe(['pending' => false, 'failed' => true]);
    expect($page->getContent())->not->toContain('DISCOUNT1');
});
