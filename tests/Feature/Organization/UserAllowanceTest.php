<?php

// The one rule for taking on another user: the organization is not deactivated and its plan has not
// run out of standard users. Adding a user by hand (the add-user gate) and self-registration use it,
// and they must not disagree (finding 2 of docs/plans/self-registration.md).

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Enums\AccessType;
use App\Organization;
use App\OrgUserRegistration;
use App\Services\Organization\UserAllowance;
use App\Support\Facades\AccountManager;
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

/**
 * An app that hands out the organization's registration link, whose default user roles give these
 * seats. The app's plan offers basic users only when asked, as a plan without them counts a basic
 * role as a standard one.
 *
 * @param  string|array<int, string>  $access_types
 */
function openRegistration(Organization $organization, string|array $access_types = 'standard', bool $basic_enabled = false): AppInstance
{
    $application = Application::factory()->create();
    $roles = collect((array) $access_types)->map(fn (string $access_type) => AppRole::factory()->create([
        'application_id' => $application->id,
        'access_type' => $access_type,
    ]));
    $version = AppVersion::factory()->create([
        'application_id' => $application->id,
        'roles' => ['order' => $roles->pluck('id')->all(), 'default_user_groups' => $roles->pluck('id')->all()],
    ]);
    $plan = AppPlan::factory()->create(['application_id' => $application->id]);
    $plan->settings = array_merge($plan->settings, [
        'self_registration_enabled' => true,
        'standard' => array_merge($plan->settings['standard'], ['max' => null]),
        'basic' => array_merge($plan->settings['basic'], ['enabled' => $basic_enabled, 'name' => $basic_enabled ? 'Basic' : null]),
    ]);
    $plan->save();

    return AppInstance::factory()->create([
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

it('says the organization is full on the registration page, before anyone types anything', function () {
    openRegistration($this->organization);
    limitStandardUsers($this->organization, 1);

    $this->get('/public/org/demo/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/OrgRegister')
            ->where('fullMessage', __('messages.org_registration.full')));
});

it('says nothing about being full while the plan has room', function () {
    openRegistration($this->organization);
    limitStandardUsers($this->organization, 2);

    $this->get('/public/org/demo/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/OrgRegister')->where('fullMessage', null));
});

it('says it again on the details page when the last place went while the person was reading their email', function () {
    openRegistration($this->organization);
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    limitStandardUsers($this->organization, 1);

    $this->get('/public/org/demo/register/verify/'.$registration->token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/OrgRegisterComplete')
            ->where('fullMessage', __('messages.org_registration.full')));

    // Seeing the message does not use the link up: it works if a place opens again
    expect(OrgUserRegistration::where('token', $registration->token)->exists())->toBeTrue();
});

it('shows the details form while the plan has room', function () {
    openRegistration($this->organization);
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    limitStandardUsers($this->organization, 2);

    $this->get('/public/org/demo/register/verify/'.$registration->token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/OrgRegisterComplete')->where('fullMessage', null));
});

// -- The seat the person would take -----------------------------------------------------------------

function registrationPageSays(?string $message = null): Closure
{
    return fn ($page) => $page->component('Auth/OrgRegister')->where('fullMessage', $message);
}

it('does not stop someone who would take a smaller seat than the one that is full', function () {
    openRegistration($this->organization, 'minimal');
    limitStandardUsers($this->organization, 1);

    $this->get('/public/org/demo/register')->assertOk()->assertInertia(registrationPageSays(null));

    $this->post('/public/org/demo/register', ['email' => 'new@example.com'])->assertSessionHasNoErrors();
    expect(OrgUserRegistration::where('email', 'new@example.com')->exists())->toBeTrue();
});

it('registers someone into a smaller seat when the standard seats are full', function () {
    openRegistration($this->organization, 'minimal');
    limitStandardUsers($this->organization, 1);
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post('/public/org/demo/register/verify/'.$registration->token, [
        'username' => 'newperson',
        'first_name' => 'New',
        'last_name' => 'Person',
    ])->assertSessionHasNoErrors();

    expect(User::where('username', 'newperson')->exists())->toBeTrue();
});

it('counts a basic role against the basic seats and not the standard ones', function () {
    openRegistration($this->organization, 'basic', basic_enabled: true);

    // Standard is full, basic is not
    limitStandardUsers($this->organization, 1);
    $this->get('/public/org/demo/register')->assertInertia(registrationPageSays(null));

    // Basic is full, standard has room
    limitStandardUsers($this->organization, 5);
    User::where('username', 'demo')->update(['access_type' => 'basic']);
    $this->get('/public/org/demo/register')->assertInertia(registrationPageSays(__('messages.org_registration.full')));
});

it('counts a basic role as a standard one on a plan without basic users', function () {
    openRegistration($this->organization, 'basic', basic_enabled: false);
    limitStandardUsers($this->organization, 1);

    $this->get('/public/org/demo/register')->assertInertia(registrationPageSays(__('messages.org_registration.full')));
});

it('takes the biggest seat of the default roles', function () {
    $app = openRegistration($this->organization, ['minimal', 'standard']);

    expect((new UserAllowance($this->organization))->accessTypeFor([$app]))->toBe(AccessType::STANDARD);
});

it('works the same for a plan that counts users for the whole organization', function () {
    $this->organization->plan->type = 'package';
    $this->organization->plan->save();
    $this->organization->refresh();
    openRegistration($this->organization, 'minimal');
    limitStandardUsers($this->organization, 1);

    $this->get('/public/org/demo/register')->assertInertia(registrationPageSays(null));
});

it('counts the cap on the app plan when the organization pays for each app and its own plan has none', function () {
    $app = openRegistration($this->organization, 'standard');
    $app->plan->updateSettings(['standard.max' => 1]);
    $app->plan->save();
    $this->organization->plan->updateSettings(['standard.max' => null]);
    $this->organization->plan->save();

    // The app has the one standard user its plan allows
    $users = Mockery::mock();
    $users->shouldReceive('appStandardUsers')->andReturn([new stdClass]);
    $users->shouldReceive('appBasicUsers')->andReturn([]);
    AccountManager::shouldReceive('users')->andReturn($users);

    $this->get('/public/org/demo/register')->assertInertia(registrationPageSays(__('messages.org_registration.full')));
});
