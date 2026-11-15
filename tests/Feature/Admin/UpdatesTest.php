<?php

use App\AppInstance;
use App\AppRole;
use App\DefinitionChange;
use App\Organization;
use App\Updates\AspectRegistry;
use App\Updates\PendingUpdates;
use App\Updates\UpdateDrift;
use App\UpdateState;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * What is out of date, shown to admins: how much and why, with a way to take it
 * as up to date when there is nothing to do, and highlights where they will see it.
 */
uses(TestCase::class, RefreshDatabase::class, TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    // Activating an app makes its groups in the directory, as the other app tests do
    setupAccountManagerDriver('ldap');
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

/** An active instance taken as up to date, and an admin to look at it */
function updatesInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $instance = test()->runActivate($plan, Organization::find(1), $app);
    app(UpdateDrift::class)->baseline($instance->fresh());
    DefinitionChange::query()->delete();

    return $instance->fresh();
}

function updatesAdmin(): User
{
    return User::find(1);
}

function outOfDate(AppInstance $instance): array
{
    return UpdateState::drifted()->where('app_instance_id', $instance->id)->orderBy('aspect')->pluck('aspect')->all();
}

it('lists what is out of date by the edit that did it and by instance', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    $change = DefinitionChange::sole();

    $this->actingAs(updatesAdmin())
        ->get('/admin/server/updates')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Updates/UpdatesList')
            ->where('summary.instances', 1)
            ->where('summary.organizations', 1)
            ->where('summary.aspects', fn ($aspects) => collect($aspects)->pluck('key')->contains('settings') && collect($aspects)->pluck('key')->contains('release'))
            ->where('groups.0.change.id', $change->id)
            ->where('groups.0.change.subject', $change->subject_label)
            ->where('groups.0.change.changed', $change->changed)
            ->where('groups.0.instances', 1)
            ->where('instances.0.id', $instance->id)
            ->where('instances.0.organization.id', $instance->organization_id)
            ->where('instances.0.aspects', fn ($aspects) => collect($aspects)->pluck('key')->contains('settings')
                && collect($aspects)->every(fn ($aspect) => $aspect['change']['id'] === $change->id))
            ->where('pagination.total', 1));
});

it('says so when nothing is out of date', function () {
    updatesInstance();

    $this->actingAs(updatesAdmin())
        ->get('/admin/server/updates')
        ->assertInertia(fn ($page) => $page
            ->where('summary.instances', 0)
            ->where('summary.aspects', [])
            ->where('groups', [])
            ->where('instances', []));
});

it('shows what was found without an edit being seen as a group of its own', function () {
    $instance = updatesInstance();
    DB::table('app_plans')->where('id', $instance->plan_id)->update(['settings' => json_encode(['base' => ['storage' => 9]])]);
    $this->artisan('updates:scan')->assertSuccessful();

    $this->actingAs(updatesAdmin())
        ->get('/admin/server/updates')
        ->assertInertia(fn ($page) => $page
            ->where('groups.0.change', null)
            ->where('groups.0.instances', 1));
});

it('narrows the list to one edit or one aspect', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    $change = DefinitionChange::sole();

    $this->actingAs(updatesAdmin());

    $this->get('/admin/server/updates?change='.$change->id)->assertInertia(fn ($page) => $page->where('instances.0.id', $instance->id));
    $this->get('/admin/server/updates?change=none')->assertInertia(fn ($page) => $page->where('instances', []));
    $this->get('/admin/server/updates?aspect=settings')->assertInertia(fn ($page) => $page->where('instances.0.id', $instance->id));
    $this->get('/admin/server/updates?aspect=sso')->assertInertia(fn ($page) => $page->where('instances', []));
});

it('does not take a filter that is not an edit or "none"', function () {
    updatesInstance();

    $this->actingAs(updatesAdmin())->get('/admin/server/updates?change=abc')->assertSessionHasErrors('change');
});

it('takes what is out of date as up to date for the aspects picked, and notes who did', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    expect(outOfDate($instance))->toContain('release', 'settings');

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(updatesAdmin())
        ->post('/admin/server/updates/dismiss', ['instance_ids' => [$instance->id], 'aspects' => ['settings']]);

    $state = UpdateState::where('app_instance_id', $instance->id)->where('aspect', 'settings')->first();
    expect(outOfDate($instance))->toContain('release')->not->toContain('settings')
        ->and($state->dismissed_by_id)->toBe(updatesAdmin()->id)
        ->and($state->dismissed_at)->not->toBeNull()
        // nothing was run
        ->and(DB::table('tasks')->where('action_slug', 'application_update_step')->exists())->toBeFalse();
});

it('takes everything an edit put out of date as up to date', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    $change = DefinitionChange::sole();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(updatesAdmin())->post('/admin/server/updates/dismiss', ['change' => (string) $change->id]);

    expect(outOfDate($instance))->toBe([]);
});

it('takes as up to date only what was asked for', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    $before = outOfDate($instance);

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(updatesAdmin())
        ->post('/admin/server/updates/dismiss', ['instance_ids' => [$instance->id], 'aspects' => ['sso']]);

    expect(outOfDate($instance))->toBe($before)
        ->and(UpdateState::where('app_instance_id', $instance->id)->whereNotNull('dismissed_at')->exists())->toBeFalse();
});

it('asks for something to take as up to date', function () {
    updatesInstance();

    $this->actingAs(updatesAdmin())->post('/admin/server/updates/dismiss', [])->assertSessionHasErrors(['instance_ids', 'change']);
});

it('forgets a dismissal when the same thing goes out of date again', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);
    app(UpdateDrift::class)->dismiss($instance->fresh(), null, updatesAdmin()->id);
    expect(UpdateState::where('app_instance_id', $instance->id)->whereNotNull('dismissed_at')->exists())->toBeTrue();

    editPlan($instance->fresh()->plan, 6);

    expect(UpdateState::drifted()->where('app_instance_id', $instance->id)->whereNotNull('dismissed_at')->exists())->toBeFalse();
});

it('looks at every instance again when asked', function () {
    $instance = updatesInstance();
    DB::table('app_plans')->where('id', $instance->plan_id)->update(['settings' => json_encode(['base' => ['storage' => 9]])]);
    expect(outOfDate($instance))->toBe([]);

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(updatesAdmin())->post('/admin/server/updates/scan');

    expect(outOfDate($instance))->toContain('release');
});

it('counts the instances that are out of date, by organization and for the menu', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);

    expect(app(PendingUpdates::class)->count())->toBe(1)
        ->and(app(PendingUpdates::class)->byOrganization([$instance->organization_id, 999]))->toBe([$instance->organization_id => 1]);

    $this->actingAs(updatesAdmin())->get('/admin/server/updates')->assertInertia(fn ($page) => $page->where('pending_updates_count', 1));
});

it('names the aspects of apps whose own aspects are not registered everywhere', function () {
    $registry = app(AspectRegistry::class);

    expect($registry->label('release'))->toBe(__('admin.update_aspects.release'))
        ->and($registry->label('feature.collabora_online'))->toBe('Collabora Online');
});

it('shows it on the dashboard and the list of organizations', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);

    $this->actingAs(updatesAdmin());

    $this->get('/admin')->assertInertia(fn ($page) => $page
        ->where('pending_updates.instances', 1)
        ->where('pending_updates.organizations', 1));

    $this->get('/admin/organizations')->assertInertia(fn ($page) => $page
        ->where('organizations', fn ($organizations) => collect($organizations)->firstWhere('id', $instance->organization_id)['out_of_date'] === 1));
});

it('shows what an app is out of date for, and ticks the steps that bring it up to date', function () {
    $instance = updatesInstance();
    editPlan($instance->plan, 5);

    $this->actingAs(updatesAdmin())
        ->get("/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Apps/AppEdit')
            ->where('updates', fn ($updates) => collect($updates)->pluck('key')->contains('settings'))
            ->where('update_steps', function ($steps) {
                $steps = collect($steps)->keyBy('key');

                return $steps['settings-job']['out_of_date'] === true
                    && $steps['settings-job']['checked'] === true
                    && $steps['release']['out_of_date'] === true
                    // nothing changed that single sign-on is about
                    && ($steps['sso']['out_of_date'] ?? false) === false;
            }));
});

it('does not tick a step for what has not changed', function () {
    $instance = updatesInstance();

    $this->actingAs(updatesAdmin())
        ->get("/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('updates', [])
            ->where('update_steps', fn ($steps) => collect($steps)->every(fn ($step) => $step['out_of_date'] === false)));
});

it('tells the admin who edits a role how many instances use it', function () {
    $instance = updatesInstance();
    $role = AppRole::where('application_id', $instance->application_id)->where('ignore_role', false)->firstOrFail();

    $this->actingAs(updatesAdmin())
        ->post("/admin/apps/{$instance->application->slug}/roles/{$role->id}", [
            'label' => $role->label,
            'sub_name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description ?: 'A role',
            'category' => $role->category ?: 'general',
            'access_type' => $role->access_type->value,
            'ignore_role' => true,
            'implied_roles' => [],
        ])
        ->assertSessionHas('warning', __('admin.updates.edit_impact', ['subject' => DefinitionChange::latest('id')->first()->subject_label, 'count' => 1]));
});
