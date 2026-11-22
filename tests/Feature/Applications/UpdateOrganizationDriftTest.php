<?php

use App\Actions\Organizations\SubscriptionUpdate;
use App\DefinitionChange;
use App\Organization;
use App\OrganizationUpdateState;
use App\Services\SubscriptionService;
use App\Support\Facades\Action;
use App\Support\Facades\Billing;
use App\Support\SubscriptionSnapshot;
use App\Updates\OrganizationDrift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

it('takes what is out of date as up to date, for the aspects picked, and notes who did', function () {
    $organization = subscribedOrganization();
    flipDomains($organization);

    app(OrganizationDrift::class)->dismiss($organization->fresh(), ['capabilities'], 1);

    $state = OrganizationUpdateState::where('organization_id', $organization->id)->where('aspect', 'capabilities')->first();
    expect(outOfDateSubscription($organization))->toBe([])
        ->and($state->dismissed_by_id)->toBe(1)
        ->and($state->dismissed_at)->not->toBeNull();
});
