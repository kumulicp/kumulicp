<?php

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppPlan;
use App\Console\Calls\ScanUpdateDrift;
use App\DefinitionChange;
use App\Jobs\Updates\ScanOrganizationsForDrift;
use App\Organization;
use App\OrganizationUpdateState;
use App\Plan;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\Billing;
use App\Support\SubscriptionSnapshot;
use App\Task;
use App\Updates\AutoUpdates;
use App\Updates\Detection\ImpactResolver;
use App\Updates\OrganizationDrift;
use App\Updates\PendingUpdates;
use App\Updates\UpdatePolicy;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * What an organization's subscription is out of date for: found the way an app
 * instance's is, from the edits to its plan and by the nightly scan, shown with the
 * rest of what is out of date, and brought up to date by a SubscriptionUpdate.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    setupAccountManagerDriver('ldap');
});

/** The seeded organization, with its subscription taken as up to date */
function subscribedOrganization(): Organization
{
    (new TestSupports)->seed();

    $organization = Organization::find(1);
    app(OrganizationDrift::class)->baseline($organization);
    DefinitionChange::query()->delete();

    return $organization->fresh();
}

/** Whether the organization can have domains, turned the other way */
function flipDomains(Organization $organization): void
{
    $plan = $organization->plan;
    $plan->domain_enabled = ! $plan->domain_enabled;
    $plan->save();
}

function outOfDateSubscription(Organization $organization): array
{
    return OrganizationUpdateState::drifted()->where('organization_id', $organization->id)->orderBy('aspect')->pluck('aspect')->all();
}

// What is stored

it('stores what a subscription update settled on, and gives it back for the next to compare', function () {
    (new TestSupports)->seed();
    $organization = Organization::find(1);
    $drift = app(OrganizationDrift::class);
    $plans = ['base' => ['id' => 3, 'name' => 'Basic', 'label' => null]];

    expect($drift->previous($organization))->toBeNull();

    $drift->record($organization, new SubscriptionSnapshot($plans, 'billing-1', 'access-1'), 12);

    $previous = $drift->previous($organization);
    expect($previous->plans)->toBe($plans)
        ->and($previous->billing)->toBe('billing-1')
        ->and($previous->access)->toBe('access-1')
        ->and($drift->states($organization)->keys()->sort()->values()->all())->toBe(['access', 'billing', 'capabilities', 'plans'])
        ->and($drift->states($organization)['billing']->task_id)->toBe(12);
});

it('reads what was kept in the organization settings before, and compares with it', function () {
    (new TestSupports)->seed();
    $organization = Organization::find(1);
    $organization->updateSetting(OrganizationDrift::LEGACY_SETTING, (new SubscriptionSnapshot(['base' => ['id' => 1, 'name' => 'A', 'label' => null]], 'billing-1', 'access-1'))->toArray());
    $organization->save();
    $drift = app(OrganizationDrift::class);

    expect($drift->previous($organization->fresh())->billing)->toBe('billing-1');

    // Where it stood is stored, so it is compared with: what it works out now is not that
    $drifted = $drift->refresh($organization->fresh());

    expect($drifted)->toContain('billing', 'access')->not->toContain('capabilities')
        ->and($drift->states($organization)->has('billing'))->toBeTrue();
});

it('does not flag a subscription nothing is stored for', function () {
    (new TestSupports)->seed();
    $organization = Organization::find(1);

    expect(app(OrganizationDrift::class)->refresh($organization))->toBe([])
        ->and(app(OrganizationDrift::class)->drift($organization))->toBe([]);
});

it('takes the subscription as up to date once, for one that has nothing stored', function () {
    (new TestSupports)->seed();
    $organization = Organization::find(1);
    $drift = app(OrganizationDrift::class);

    expect($drift->baseline($organization))->toBeTrue()
        ->and($drift->baseline($organization))->toBeFalse()
        ->and($drift->drift($organization))->toBe([]);
});

// Settling

it('stores what it settled on when a subscription update is done, and does not tell the billing provider again for nothing', function () {
    (new TestSupports)->seed();
    $organization = Organization::find(1);
    Queue::fake();
    Bus::fake();
    Billing::shouldReceive('update')->once();

    foreach ([1, 2] as $run) {
        $task = Action::execute(new SubscriptionUpdate($organization, new SubscriptionService($organization)));
        SubscriptionUpdate::run($task);
        $organization = $organization->fresh();
    }

    $drift = app(OrganizationDrift::class);
    expect($drift->states($organization)->keys()->sort()->values()->all())->toBe(['access', 'billing', 'capabilities', 'plans'])
        ->and($drift->drift($organization))->toBe([])
        ->and($organization->setting(OrganizationDrift::LEGACY_SETTING))->toBeNull();
});

// Noticing

it('notes an edit to the plan an organization is on, and what it put out of date', function () {
    $organization = subscribedOrganization();

    flipDomains($organization);

    $change = DefinitionChange::sole();
    expect($change->subject_type)->toBe(Plan::class)
        ->and($change->changed)->toContain('domain_enabled')
        ->and(outOfDateSubscription($organization))->toBe(['capabilities']);

    $state = OrganizationUpdateState::drifted()->first();
    expect($state->definition_change_id)->toBe($change->id)
        ->and($state->drift_detected_at)->not->toBeNull();
});

it('does not note an edit to what a plan is called or shown as', function () {
    $organization = subscribedOrganization();

    $plan = $organization->plan;
    $plan->name = 'Renamed';
    $plan->description = 'Something else';
    $plan->display_order = 99;
    $plan->save();

    expect(DefinitionChange::count())->toBe(0)
        ->and(outOfDateSubscription($organization))->toBe([]);
});

it('looks only at the organizations on the plan that was edited', function () {
    $organization = subscribedOrganization();
    $other = Plan::factory()->create();
    $resolver = app(ImpactResolver::class);

    expect($resolver->organizations(Plan::class, $organization->plan_id)->pluck('id')->all())->toContain($organization->id)
        ->and($resolver->organizations(Plan::class, $other->id)->exists())->toBeFalse()
        // an app's plans are about its instances
        ->and($resolver->organizations(AppPlan::class, 1))->toBeNull();
});

it('forgets what is out of date when the edit is undone', function () {
    $organization = subscribedOrganization();

    flipDomains($organization);
    expect(outOfDateSubscription($organization->fresh()))->toBe(['capabilities']);

    flipDomains($organization->fresh());

    expect(outOfDateSubscription($organization))->toBe([]);
});

it('finds, with the nightly scan, what an edit made without the models changed', function () {
    $organization = subscribedOrganization();

    DB::table('plans')->where('id', $organization->plan_id)->update(['email_enabled' => ! $organization->plan->email_enabled]);

    expect(outOfDateSubscription($organization))->toBe([]);

    (new ScanUpdateDrift(now: true))();

    expect(outOfDateSubscription($organization))->toBe(['capabilities'])
        ->and(OrganizationUpdateState::drifted()->whereNotNull('definition_change_id')->exists())->toBeFalse();
});

it('takes what is out of date as up to date, for the aspects picked, and notes who did', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);

    app(OrganizationDrift::class)->dismiss($organization->fresh(), ['capabilities'], 1);

    $state = OrganizationUpdateState::where('organization_id', $organization->id)->where('aspect', 'capabilities')->first();
    expect(outOfDateSubscription($organization))->toBe([])
        ->and($state->dismissed_by_id)->toBe(1)
        ->and($state->dismissed_at)->not->toBeNull();
});

// On its own

it('is automatic like the quiet aspects of an app, once automatic updates are on', function () {
    $policy = app(UpdatePolicy::class);

    expect($policy->defaultMode('billing'))->toBe(UpdatePolicy::AUTO)
        ->and($policy->defaultMode('capabilities'))->toBe(UpdatePolicy::AUTO)
        ->and(collect($policy->all())->firstWhere('key', 'access'))->toMatchArray(['label' => __('admin.update_organization_aspects.access'), 'apps' => []]);
});

it('updates the subscription on its own only once automatic updates are on', function () {
    $organization = subscribedOrganization();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    flipDomains($organization);
    $scan = fn () => (new ScanOrganizationsForDrift([$organization->id]))->handle(app(OrganizationDrift::class), app(AutoUpdates::class));

    $scan();
    expect(Task::where('action_slug', 'subscription_update')->exists())->toBeFalse();

    app(UpdatePolicy::class)->enable(true);
    $scan();

    expect(Task::where('action_slug', 'subscription_update')->count())->toBe(1)
        ->and(OrganizationUpdateState::where('organization_id', $organization->id)->where('aspect', 'capabilities')->first()->apply_attempts)->toBe(1);

    // Too soon to try again
    $scan();
    expect(Task::where('action_slug', 'subscription_update')->count())->toBe(1);
});

// Seen

it('lists the organizations that are out of date, with what for and why', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);
    $change = DefinitionChange::sole();

    $this->actingAs(User::find(1));

    $this->get('/admin/server/updates')->assertInertia(fn ($page) => $page
        ->where('subscriptions.total', 1)
        ->where('subscriptions.items.0.id', $organization->id)
        ->where('subscriptions.items.0.aspects.0.key', 'capabilities')
        ->where('subscriptions.items.0.aspects.0.change.id', $change->id)
        ->where('summary.subscriptions.organizations', 1)
        ->where('pending_updates_count', 1));

    expect(app(PendingUpdates::class)->count())->toBe(1)
        ->and(app(PendingUpdates::class)->subscriptionsOf([$organization->id])[$organization->id][0]['label'])
        ->toBe(__('admin.update_organization_aspects.capabilities'));
});

it('shows it on the dashboard, the list of organizations and the organization', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);

    $this->actingAs(User::find(1));

    $this->get('/admin')->assertInertia(fn ($page) => $page->where('pending_updates.subscriptions.organizations', 1));

    $this->get('/admin/organizations')->assertInertia(fn ($page) => $page
        ->where('organizations', fn ($organizations) => collect($organizations)->firstWhere('id', $organization->id)['subscription_out_of_date'] === true));

    $this->get("/admin/organizations/{$organization->id}")->assertInertia(fn ($page) => $page
        ->where('subscription_updates.0.key', 'capabilities'));
});

it('says nothing about an organization that is up to date', function () {
    $organization = subscribedOrganization();

    $this->actingAs(User::find(1))
        ->get("/admin/organizations/{$organization->id}")
        ->assertInertia(fn ($page) => $page->where('subscription_updates', []));
});

// Bringing them up to date

it('starts a subscription update for the organizations picked', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);
    Queue::fake();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(User::find(1))
        ->post('/admin/server/updates/subscriptions/apply', ['organization_ids' => [$organization->id]]);

    expect(Task::where('action_slug', 'subscription_update')->where('organization_id', $organization->id)->count())->toBe(1);
});

it('starts one for every organization that is out of date when asked for all', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);
    Queue::fake();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(User::find(1))
        ->post('/admin/server/updates/subscriptions/apply', ['all' => true]);

    expect(Task::where('action_slug', 'subscription_update')->count())->toBe(1);
});

it('takes the subscriptions picked as up to date without updating them', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);
    Queue::fake();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(User::find(1))
        ->post('/admin/server/updates/subscriptions/dismiss', ['all' => true]);

    expect(outOfDateSubscription($organization))->toBe([])
        ->and(Task::where('action_slug', 'subscription_update')->exists())->toBeFalse();
});

it('asks for which organizations', function () {
    subscribedOrganization();

    $this->actingAs(User::find(1))
        ->post('/admin/server/updates/subscriptions/apply', [])
        ->assertSessionHasErrors(['organization_ids', 'all']);
});

it('lists them on the command line', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);

    $this->artisan('updates:scan')->expectsOutputToContain('capabilities')->assertSuccessful();
});
