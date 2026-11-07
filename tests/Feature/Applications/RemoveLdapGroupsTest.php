<?php

use App\Actions\Apps\ApplicationDelete;
use App\AppInstance;
use App\Jobs\Applications\AddLdapGroups;
use App\Jobs\Applications\RemoveLDAPGroups;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\Group;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use Illuminate\Support\Str;
use Tests\Support\Applications\DemoAppProfile;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * Deleting an app takes its groups out of LDAP. These run against the real
 * directory; the demo app's groups they make are removed after each test.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    DemoAppProfile::$multisite = false;
    $this->restoreServerInterfaces();
});

function ldapGroupsInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $instance = test()->runActivate($plan, Organization::find(1), $app);
    AddLdapGroups::dispatchSync($instance);

    return $instance;
}

function appContainer(AppInstance $instance): ?Group
{
    return Group::find(Dn::create($instance->organization, 'applications', $instance->name));
}

it('removes an app\'s container, and the role groups in it, from LDAP', function () {
    $instance = ldapGroupsInstance();
    expect(appContainer($instance))->not->toBeNull();

    RemoveLDAPGroups::dispatchSync($instance);

    expect(appContainer($instance))->toBeNull();
});

it('removes the container of a shared child that has a site of its own, not the parent\'s', function () {
    $hub = ldapGroupsInstance();
    DemoAppProfile::$multisite = true;
    $child = test()->makeChildInstance($hub, 'demo_app-'.Str::lower(Str::random(5)));
    AddLdapGroups::dispatchSync($child);
    expect(appContainer($child))->not->toBeNull();

    RemoveLDAPGroups::dispatchSync($child);

    expect(appContainer($child))->toBeNull()
        ->and(appContainer($hub))->not->toBeNull();
});

it('removes the groups while the app still exists, so it does not depend on a queue picking up a job for an app that is gone by then', function () {
    $instance = ldapGroupsInstance();
    // As on a real queue: a queued job would not have run by the time the app is deleted
    config(['queue.default' => 'database']);

    $task = Action::execute(new ApplicationDelete($instance));
    app(TaskRunner::class)->execute($task);

    expect(appContainer($instance))->toBeNull()
        ->and(AppInstance::find($instance->id))->not->toBeNull();
});
