<?php

use App\AppInstance;
use App\Console\Calls\ApplyPendingAppChanges;
use App\Events\Users\UserStorageUpdated;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\Jobs\Users\UpdateUserStorage;
use App\Organization;
use App\Services\AppInstance\AppInstancePlanService;
use App\Services\AppInstance\PendingAppChanges;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Task;
use App\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

// Feature/Subscription's global beforeEach in Pest.php seeds, activates the demo app (instance $this->demoApp)
// and acts as the demo user. A plan can always be changed; what the change does to the app itself waits
// until the app is active again.

beforeEach(function () {
    $this->organization = Organization::find(1);
    $this->changes = app(PendingAppChanges::class);

    // Two app plans to choose between, on a Pay per App base plan
    $this->support->base_1->app_plans = ['demo_app' => ['max' => 5, 'plans' => [$this->support->demo_app_1->id, $this->support->demo_app_2->id]]];
    $this->support->base_1->save();
    $this->organization->forceFill(['plan_id' => $this->support->base_1->id])->save();
    $this->demoApp->forceFill(['plan_id' => $this->support->demo_app_1->id, 'status' => 'active'])->save();
});

function setAppStatus($test, string $status): void
{
    $test->demoApp->forceFill(['status' => $status])->save();
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
    $test->actingAs(User::findOrFail($test->user->id));
}

it('lets the plan of an app be changed whatever state the app is in, unless it is going away', function (string $status, bool $allowed) {
    setAppStatus($this, $status);

    expect(Gate::inspect('change-app-plan', $this->demoApp->fresh())->allowed())->toBe($allowed);
})->with([
    'active' => ['active', true],
    'unavailable' => ['unavailable', true],
    'updating' => ['updating', true],
    'deactivating' => ['deactivating', false],
    'deactivated' => ['deactivated', false],
    'deleting' => ['deleting', false],
]);

it('does not hold anything back for an app that is active', function () {
    expect($this->changes->defer($this->demoApp, ['ldap' => true]))->toBeFalse();
    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeFalse();
});

it('writes changes down for an app that is unavailable and keeps adding to them', function () {
    setAppStatus($this, 'unavailable');

    expect($this->changes->defer($this->demoApp, ['ldap' => true]))->toBeTrue();
    expect($this->changes->defer($this->demoApp, ['storage' => true]))->toBeTrue();

    $pending = $this->demoApp->fresh()->setting(PendingAppChanges::SETTING);
    expect($pending['ldap'])->toBeTrue()->and($pending['storage'])->toBeTrue()->and($pending['since'])->not->toBeNull();
});

it('does nothing for the pending changes while the app is still unavailable', function () {
    Bus::fake();
    setAppStatus($this, 'unavailable');
    $this->changes->defer($this->demoApp, ['ldap' => true]);

    expect($this->changes->applyIfDue($this->demoApp->fresh()))->toBeFalse();

    Bus::assertNotDispatched(UpdateLDAPGroups::class);
    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeTrue();
});

it('applies the pending changes once, as soon as the app is active again', function () {
    Bus::fake();
    Event::fake([UserStorageUpdated::class]);
    setAppStatus($this, 'unavailable');
    $this->changes->defer($this->demoApp, ['ldap' => true, 'storage' => true]);

    // The app comes back
    $app = $this->demoApp->fresh();
    $app->status = 'active';
    $app->save();

    Bus::assertDispatchedTimes(UpdateLDAPGroups::class, 1);
    Event::assertDispatchedTimes(UserStorageUpdated::class, 1);
    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeFalse();

    // Saving it again, or the sweep, finds nothing left to do
    $app->fresh()->save();
    expect($this->changes->applyIfDue($this->demoApp->fresh()))->toBeFalse();
    Bus::assertDispatchedTimes(UpdateLDAPGroups::class, 1);
});

it('lets the sweep apply what a status change did not announce', function () {
    Bus::fake();
    setAppStatus($this, 'unavailable');
    $this->changes->defer($this->demoApp, ['ldap' => true]);

    // Set without model events, as a query would
    AppInstance::whereKey($this->demoApp->id)->toBase()->update(['status' => 'active']);
    Bus::assertNotDispatched(UpdateLDAPGroups::class);

    (new ApplyPendingAppChanges)();

    Bus::assertDispatchedTimes(UpdateLDAPGroups::class, 1);
    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeFalse();
});

it('leaves the per-user storage of an unavailable app until it is active', function () {
    Action::spy();
    setAppStatus($this, 'unavailable');

    $event = new UserStorageUpdated($this->organization, app_instance: $this->demoApp->fresh());
    (new UpdateUserStorage)->handle($event);

    Action::shouldNotHaveReceived('dispatch');
    expect($this->demoApp->fresh()->setting(PendingAppChanges::SETTING.'.storage'))->toBeTrue();
});

it('changes the plan of an unavailable app straight away but not what the change does to the app', function () {
    Bus::fake();
    Event::fake([UserStorageUpdated::class]);
    setAppStatus($this, 'unavailable');

    $subscription = (new SubscriptionService($this->organization))->all();
    $subscription->updateApp($this->support->demo_app_2, $this->demoApp->fresh());

    $app = $this->demoApp->fresh();
    expect($app->plan_id)->toBe($this->support->demo_app_2->id);
    expect($app->status)->toBe('unavailable');
});

it('says on the plans page that changes are waiting for the app', function () {
    setAppStatus($this, 'unavailable');
    $this->changes->defer($this->demoApp, ['plan_change' => ['customizations' => []]]);

    $service = new AppInstancePlanService($this->demoApp->fresh()->plan, $this->demoApp->fresh());

    expect($service->pendingNotice())->toBe(__('organization.subscription.pending_notice'));
    expect((new AppInstancePlanService($this->demoApp->plan, $this->demoApp->fresh()->forceFill(['settings' => []])))->pendingNotice())->toBeNull();
});

it('only creates the tasks that put a plan change into the app once it is active', function () {
    Queue::fake();
    setAppStatus($this, 'unavailable');
    $tasks = fn () => Task::where('app_instance_id', $this->demoApp->id)->pluck('action_slug')->all();

    $this->changes->defer($this->demoApp, ['plan_change' => ['customizations' => ['some_feature' => true]]]);
    expect($tasks())->toBe([]);

    $app = $this->demoApp->fresh();
    $app->status = 'active';
    $app->save();

    expect($tasks())->toContain('application_update')->toContain('process_customizations');
    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeFalse();
});

it('changes the plan of an unavailable app from the plans page and holds back the app tasks', function () {
    Queue::fake();
    setAppStatus($this, 'unavailable');
    $plan = $this->support->demo_app_2;

    $this->put("/apps/{$this->demoApp->id}/plans/{$plan->id}/select", ['customizations' => []])
        ->assertRedirect('/subscription/plans')
        ->assertSessionHas('success', __('organization.plan.updated_waiting', ['app' => $this->demoApp->label]));

    $app = $this->demoApp->fresh();
    expect($app->plan_id)->toBe($plan->id);
    // Still unavailable: the plan change did not touch the app, and the billing update was queued as usual
    expect($app->status)->toBe('unavailable');
    expect($app->setting(PendingAppChanges::SETTING.'.plan_change'))->not->toBeNull();

    $tasks = Task::where('app_instance_id', $app->id)->pluck('action_slug')->all();
    expect($tasks)->not->toContain('application_update')->not->toContain('application_upgrade')->not->toContain('process_customizations');
    expect(Task::where('organization_id', $this->organization->id)->where('action_slug', 'subscription_update')->exists())->toBeTrue();
});

it('still puts the plan change into an active app right away', function () {
    Queue::fake();
    setAppStatus($this, 'active');
    $plan = $this->support->demo_app_2;

    $this->put("/apps/{$this->demoApp->id}/plans/{$plan->id}/select", ['customizations' => []])
        ->assertRedirect('/subscription/plans')
        ->assertSessionHas('success', __('organization.plan.updated'));

    expect(PendingAppChanges::hasPending($this->demoApp->fresh()))->toBeFalse();
    expect(Task::where('app_instance_id', $this->demoApp->id)->pluck('action_slug')->all())
        ->toContain('application_update')->toContain('process_customizations');
});
