<?php

// The one rule for taking on another user: the organization is not deactivated and its plan has not
// run out of standard users. Adding a user by hand (the add-user gate) and self-registration use it,
// and they must not disagree (finding 2 of docs/plans/self-registration.md).

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Organization;
use App\OrgUserRegistration;
use App\Services\Organization\UserAllowance;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\TestSupports;

/** Gives the organization a plan with $max standard users, and one standard user already (demo). */
function limitStandardUsers(Organization $organization, ?int $max): void
{
    $organization->plan->updateSettings(['standard.max' => $max]);
    $organization->plan->save();
    User::where('username', 'demo')->update(['access_type' => 'standard']);
}

/** An app that hands out the organization's registration link. */
function openRegistration(Organization $organization): void
{
    $application = Application::factory()->create();
    $role = AppRole::factory()->create(['application_id' => $application->id]);
    $version = AppVersion::factory()->create([
        'application_id' => $application->id,
        'roles' => ['order' => [$role->id], 'default_user_groups' => [$role->id]],
    ]);
    $plan = AppPlan::factory()->create(['application_id' => $application->id]);
    $plan->settings = array_merge($plan->settings, ['self_registration_enabled' => true]);
    $plan->save();

    AppInstance::factory()->create([
        'organization_id' => $organization->id,
        'application_id' => $application->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
        'settings' => ['self_registration_enabled' => true],
    ]);
}

beforeEach(function () {
    (new TestSupports)->seed();

    $this->organization = Organization::find(1);
    RateLimiter::clear('org-register:demo:127.0.0.1');
    Notification::fake();
});

it('allows a user while the plan has room', function () {
    limitStandardUsers($this->organization, 2);

    $allowance = new UserAllowance($this->organization);

    expect($allowance->allowed())->toBeTrue()
        ->and($allowance->check()->allowed())->toBeTrue();
});

it('allows any number of users when the plan has no maximum', function () {
    limitStandardUsers($this->organization, null);

    expect((new UserAllowance($this->organization))->allowed())->toBeTrue();
});

it('refuses a user once the plan is full, with the message adding a user by hand gives', function () {
    limitStandardUsers($this->organization, 1);

    $check = (new UserAllowance($this->organization))->check();

    expect($check->allowed())->toBeFalse()
        ->and($check->message())->toBe(__('organization.user.denied.add'));
});

it('refuses a user for a deactivated organization, whatever its plan', function () {
    limitStandardUsers($this->organization, 5);
    $this->organization->status = 'deactivated';

    expect((new UserAllowance($this->organization))->allowed())->toBeFalse();
});

it('is the rule behind the add-user gate', function (?int $max, string $status, bool $expected) {
    limitStandardUsers($this->organization, $max);
    $this->organization->status = $status;
    $this->organization->save();
    Subscription::refresh();

    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    expect(Gate::allows('add-user'))->toBe($expected);
})->with([
    'room left' => [2, 'active', true],
    'no maximum' => [null, 'active', true],
    'plan full' => [1, 'active', false],
    'deactivated' => [5, 'deactivated', false],
]);

it('answers for the organization it is given, not the one of whoever is signed in', function () {
    $other = Organization::factory()->create(['slug' => 'other', 'plan_id' => $this->organization->plan_id]);
    $other->status = 'deactivated';

    $this->actingAs(User::where('username', 'demo')->firstOrFail());

    expect((new UserAllowance($other))->allowed())->toBeFalse()
        ->and((new UserAllowance($this->organization))->allowed())->toBeTrue();
});

// -- On the registration pages -------------------------------------------------------------------------

it('tells a registrant the organization is full before sending them an email', function () {
    openRegistration($this->organization);
    limitStandardUsers($this->organization, 1);

    $this->post('/public/org/demo/register', ['email' => 'new@example.com'])
        ->assertSessionHasErrors(['email' => __('messages.org_registration.full')]);

    Notification::assertNothingSent();
    expect(OrgUserRegistration::count())->toBe(0);
});

it('still sends the link while the plan has room', function () {
    openRegistration($this->organization);
    limitStandardUsers($this->organization, 2);

    $this->post('/public/org/demo/register', ['email' => 'new@example.com'])->assertSessionHasNoErrors();

    expect(OrgUserRegistration::where('email', 'new@example.com')->exists())->toBeTrue();
});

it('closes every registration page for a deactivated organization', function () {
    openRegistration($this->organization);
    $token = OrgUserRegistration::generate($this->organization, 'new@example.com')->token;
    $this->organization->status = 'deactivated';
    $this->organization->save();

    $this->get('/public/org/demo/register')->assertNotFound();
    $this->post('/public/org/demo/register', ['email' => 'new@example.com'])->assertNotFound();
    $this->get('/public/org/demo/register/pending')->assertNotFound();
    $this->get('/public/org/demo/register/verify/'.$token)->assertNotFound();

    Notification::assertNothingSent();
});
