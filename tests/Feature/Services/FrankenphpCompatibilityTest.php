<?php

use App\Organization;
use App\Services\OrganizationService;
use App\Support\Facades\Application;
use App\Support\Facades\Organization as OrganizationFacade;
use App\User;
use Illuminate\Support\Facades\Facade;
use Tests\Support\TestSupports;

/*
|--------------------------------------------------------------------------
| FrankenPHP / Octane worker-mode compatibility
|--------------------------------------------------------------------------
|
| Under FrankenPHP/Octane worker mode, one booted application serves many
| requests, so any singleton() service that caches per-request state (e.g.
| "the current organization") leaks that state into the next request unless
| the container is reset in between. Pest boots a brand-new application for
| every test method, which is exactly why ordinary Feature tests can never
| catch this: each test already gets a guaranteed-fresh container regardless
| of whether a binding is singleton() or scoped().
|
| These tests simulate a worker's request boundary within a single test -
| forgetting scoped container instances and clearing facade caches, exactly
| as App\Octane\Listeners\FlushApplicationFacadeState (registered in
| config/octane.php) and Laravel's own queue worker do between jobs - to
| prove state from one "request" does not survive into the next.
*/

it('resolves the current organization fresh for each simulated request instead of leaking the previous one', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    // Explicit usernames: the factory's default (fake()->word(), not unique)
    // can collide when two users are created in the same test.
    $userA = User::factory()->create(['organization_id' => $orgA->id, 'username' => 'frankenphp-test-user-a']);
    $userB = User::factory()->create(['organization_id' => $orgB->id, 'username' => 'frankenphp-test-user-b']);

    $this->actingAs($userA);
    expect(OrganizationFacade::account()->id)->toBe($orgA->id);

    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $this->actingAs($userB);
    expect(OrganizationFacade::account()->id)->toBe($orgB->id);
});

it('demonstrates why the real binding must be scoped(), not singleton(), across a worker request boundary', function () {
    // Binds the same OrganizationService class two ways, under fresh
    // (non-deferred) abstract names, to isolate exactly what scoped() buys
    // over singleton() - the mechanism the real 'organizations' binding in
    // ActionServiceProvider relies on. This is what proves the test above
    // would actually have caught the original singleton-caused leak.
    app()->singleton('test.organization.singleton', fn () => new OrganizationService);
    app()->scoped('test.organization.scoped', fn () => new OrganizationService);

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create(['organization_id' => $orgA->id, 'username' => 'frankenphp-test-user-c']);
    $userB = User::factory()->create(['organization_id' => $orgB->id, 'username' => 'frankenphp-test-user-d']);

    $this->actingAs($userA);
    expect(app('test.organization.singleton')->account()->id)->toBe($orgA->id);
    expect(app('test.organization.scoped')->account()->id)->toBe($orgA->id);

    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $this->actingAs($userB);

    // scoped() was rebuilt for the new "request": correctly reflects org B.
    expect(app('test.organization.scoped')->account()->id)->toBe($orgB->id);

    // singleton() was never reconstructed: still serving org A's data even
    // though the authenticated user is now in org B - the exact leak the
    // real 'organizations' binding avoids by using scoped() instead.
    expect(app('test.organization.singleton')->account()->id)->toBe($orgA->id);
});

it('does not serve a stale AppInstance snapshot across simulated requests', function () {
    $support = new TestSupports;
    $support->seed();
    $support->activateDemoApp();

    $organization = Organization::find(1);
    $app_instance = $support->demo_app->instances()->where('organization_id', $organization->id)->first();

    $cached = Application::instance($app_instance);

    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $fresh = Application::instance($app_instance);

    // A new ApplicationService (and therefore a new instance() cache) was
    // built for the simulated second request, instead of handing back the
    // wrapper memoized during the first one.
    expect($fresh)->not->toBe($cached);
});
