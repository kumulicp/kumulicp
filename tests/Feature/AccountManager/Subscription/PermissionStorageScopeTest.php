<?php

use App\AppPlan;
use App\AppVersion;
use App\Jobs\Applications\AddLdapGroups;
use App\Services\SubscriptionService;
use App\Services\UserPermissionsService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Application as AppFacade;
use App\User;
use Illuminate\Support\Facades\Notification;
use Tests\Support\TestSupports;

it('only requests a storage update for the app whose access type actually changed', function (string $driver) {
    skipUnlessDriver('ldap', $driver);
    setupAccountManagerDriver($driver);
    $support = new TestSupports;
    $support->seed();

    // First demo_app instance for the organization.
    $support->activateDemoApp();
    $appA = $support->demo_app->instances()->first();
    $support->createDemoAppPlans();
    $support->addUsers();

    $admin = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($admin);
    $organization = $admin->organization;

    // Second demo_app instance for the same organization/application, reusing the
    // application and roles already registered by the first activation.
    $demo_app = $support->demo_app;
    $app_plan = AppPlan::factory()->create(['application_id' => $demo_app->id]);
    $version = AppVersion::factory()->create(['application_id' => $demo_app->id]);
    $version->roles = ['order' => $demo_app->roles()->pluck('id')->all()];
    $version->save();
    $appB = AppFacade::activate($organization, $demo_app, $version, $app_plan)->get();
    AddLdapGroups::dispatch($appB);
    $appB->status = 'active';
    $appB->save();

    // demo_app_1 has different storage amounts for standard (1) vs basic (0.5) users.
    $subscription = (new SubscriptionService($organization))->all();
    $subscription->updateApp($support->demo_app_1, $appA);
    $subscription->updateApp($support->demo_app_1, $appB);

    // Give the user a standard role on both apps first, without side effects.
    (new UserPermissionsService)->updatePermissions(
        user: AccountManager::users()->find('testing1'),
        user_id: 'testing1',
        organization: $organization,
        permissions_input: [$appA->id => ['demo_role'], $appB->id => ['demo_role']],
        with_side_effects: false,
    );

    Notification::fake();

    $dispatched = [];
    Action::shouldReceive('dispatch')
        ->andReturnUsing(function ($category, $action, $params, $parent_task = null) use (&$dispatched) {
            $dispatched[] = ['action' => $action, 'app_instance_id' => $params[0]->id];

            return null;
        });
    Action::shouldReceive('execute')->andReturnNull();

    // Downgrade the user to basic on appA only (a storage-relevant change); appB is
    // resubmitted unchanged.
    (new UserPermissionsService)->updatePermissions(
        user: AccountManager::users()->find('testing1'),
        user_id: 'testing1',
        organization: $organization,
        permissions_input: [$appA->id => ['basic_demo_role'], $appB->id => ['demo_role']],
        with_side_effects: true,
    );

    $storage_dispatches = collect($dispatched)->where('action', 'process_user_options')->pluck('app_instance_id');
    expect($storage_dispatches->all())->toBe([$appA->id]);

    // Permission sync itself still runs for both apps regardless of storage impact.
    $permission_dispatches = collect($dispatched)->where('action', 'process_permissions')->pluck('app_instance_id')->sort()->values();
    expect($permission_dispatches->all())->toBe(collect([$appA->id, $appB->id])->sort()->values()->all());
})->with('account_manager_drivers');
