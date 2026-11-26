<?php

// The public self-registration flow (/public/org/{slug}/register): who can see it, the email step
// with its throttle and captcha, the verification link, and creating the user.
//
// Phase 0 of docs/plans/self-registration.md. The tests that are not skipped describe what the flow
// does today and must keep passing through the fixes. Each skipped test is a finding of that plan
// written as the behavior we want, with the finding's number in its skip reason: the phase that
// fixes it removes the skip.
//
// Not covered here: that the user lands in the default roles of the apps that opted in. The database
// account manager does not keep app roles (addToDefaultUserGroups does nothing), so that needs the
// LDAP suite.

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\Events\Users\UserCreated as UserCreatedEvent;
use App\NewUserCode;
use App\Notifications\OrgRegistrationComplete;
use App\Notifications\OrgRegistrationVerification;
use App\Organization;
use App\OrgUserRegistration;
use App\Support\Facades\Settings;
use App\SuborgUser;
use App\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\TestSupports;

/**
 * An app instance the organization's registration link would hand out. Each switch can be turned off
 * to get one of the ways the link stays closed: the plan does not allow it, the administrator has not
 * turned it on, or the version has no default user roles.
 *
 * @param  array{plan?: bool, instance?: bool, roles?: bool}  $switches
 */
function selfRegistrationApp(Organization $organization, array $switches = []): AppInstance
{
    $switches = array_merge(['plan' => true, 'instance' => true, 'roles' => true], $switches);

    $application = Application::factory()->create();
    $role = AppRole::factory()->create(['application_id' => $application->id]);
    $version = AppVersion::factory()->create([
        'application_id' => $application->id,
        'roles' => ['order' => [$role->id], 'default_user_groups' => $switches['roles'] ? [$role->id] : []],
    ]);

    $plan = AppPlan::factory()->create(['application_id' => $application->id]);
    $plan->settings = array_merge($plan->settings, ['self_registration_enabled' => $switches['plan']]);
    $plan->save();

    return AppInstance::factory()->create([
        'organization_id' => $organization->id,
        'application_id' => $application->id,
        'version_id' => $version->id,
        'plan_id' => $plan->id,
        'settings' => ['self_registration_enabled' => $switches['instance']],
    ]);
}

function registrationDetails(array $overrides = []): array
{
    return array_merge([
        'username' => 'newperson',
        'first_name' => 'New',
        'last_name' => 'Person',
        'phone_number' => '555-0100',
    ], $overrides);
}

beforeEach(function () {
    (new TestSupports)->seed();

    $this->organization = Organization::find(1);
    $this->app_instance = selfRegistrationApp($this->organization);

    $this->base = '/public/org/demo/register';

    RateLimiter::clear('org-register:demo:127.0.0.1');
    Notification::fake();
});

// -- Who can see the link ---------------------------------------------------------------------------

it('shows the first step when an app has registration on', function () {
    $this->get($this->base)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/OrgRegister')
            ->where('organization.slug', 'demo')
            ->where('organization.name', 'Demo'));
});

dataset('closed_registration', [
    'the administrator has not turned it on' => [['instance' => false]],
    'the plan does not allow it' => [['plan' => false]],
    'the version has no default user roles' => [['roles' => false]],
]);

it('is closed on every step unless an app opts in', function (array $switches) {
    AppInstance::query()->delete();
    selfRegistrationApp($this->organization, $switches);
    $token = OrgUserRegistration::generate($this->organization, 'someone@example.com')->token;

    $this->get($this->base)->assertNotFound();
    $this->post($this->base, ['email' => 'someone@example.com'])->assertNotFound();
    $this->get($this->base.'/pending')->assertNotFound();
    $this->get($this->base.'/verify/'.$token)->assertNotFound();
    $this->post($this->base.'/verify/'.$token, registrationDetails())->assertNotFound();

    Notification::assertNothingSent();
    expect(User::where('username', 'newperson')->exists())->toBeFalse();
})->with('closed_registration');

it('is closed for an organization with no apps', function () {
    AppInstance::query()->delete();

    $this->get($this->base)->assertNotFound();
});

it('is closed in single-organization mode', function () {
    useSingleTenancy();

    $this->get($this->base)->assertNotFound();
    $this->post($this->base, ['email' => 'someone@example.com'])->assertNotFound();
});

it('only counts the apps that opted in', function () {
    $second = selfRegistrationApp($this->organization, ['instance' => false]);
    $third = selfRegistrationApp($this->organization, ['plan' => false]);

    $apps = $this->organization->fresh()->selfRegistrationApps();

    expect($apps->pluck('id')->all())->toBe([$this->app_instance->id])
        ->and($apps->pluck('id')->all())->not->toContain($second->id, $third->id);
});

// -- Step 1: the email ------------------------------------------------------------------------------

it('sends a verification link to the address and stores a registration that lasts an hour', function () {
    $this->post($this->base, ['email' => 'new@example.com'])
        ->assertRedirect(route('public.org.register.pending', ['organization' => 'demo']));

    Notification::assertSentOnDemand(
        OrgRegistrationVerification::class,
        function ($notification, $channels, $notifiable) {
            $url = $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'new@example.com'
                && str_ends_with($url, '/public/org/demo/register/verify/'.$notification->registration->token);
        },
    );

    $registration = OrgUserRegistration::where('email', 'new@example.com')->sole();
    expect($registration->organization_id)->toBe($this->organization->id)
        ->and($registration->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();
});

it('shows the pending page', function () {
    $this->get($this->base.'/pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/OrgRegisterPending')->where('organization.slug', 'demo'));
});

it('replaces the earlier link when the same address asks again', function () {
    $first = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base, ['email' => 'new@example.com'])->assertSessionHasNoErrors();

    $second = OrgUserRegistration::where('email', 'new@example.com')->sole();
    expect($second->token)->not->toBe($first->token)
        ->and(OrgUserRegistration::count())->toBe(1);
});

it('rejects a missing or malformed address without sending anything', function (?string $email) {
    $this->post($this->base, ['email' => $email])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(OrgUserRegistration::count())->toBe(0);
})->with([
    'empty' => [''],
    'missing' => [null],
    'not an address' => ['not-an-email'],
]);

it('sends no verification link to an address that already has an account', function () {
    $this->post($this->base, ['email' => 'demo@example.com']);

    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 0);
    expect(OrgUserRegistration::count())->toBe(0);
});

it('stops an address after five tries from the same IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post($this->base, ['email' => "person{$i}@example.com"])->assertSessionHasNoErrors();
    }

    $this->post($this->base, ['email' => 'person6@example.com'])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Too many registration attempts');
    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 5);
});

// -- Step 1: the captcha ----------------------------------------------------------------------------

it('does not ask for a captcha when none is configured', function () {
    $this->post($this->base, ['email' => 'new@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 1);
});

it('passes the captcha token to the provider with the secret and accepts a success', function (string $provider, string $url) {
    Settings::update('captcha_provider', $provider);
    Settings::update('captcha_secret_key', 'secret-key');
    Http::fake([$url => Http::response(['success' => true])]);

    $this->post($this->base, ['email' => 'new@example.com', 'captcha_token' => 'the-token'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://'.parse_url($url, PHP_URL_HOST))
        && $request['secret'] === 'secret-key'
        && $request['response'] === 'the-token');
    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 1);
})->with([
    'turnstile' => ['turnstile', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'],
    'hcaptcha' => ['hcaptcha', 'https://hcaptcha.com/siteverify'],
]);

it('refuses a registration when the captcha is missing or fails', function (?string $token, bool $provider_says_ok) {
    Settings::update('captcha_provider', 'turnstile');
    Settings::update('captcha_secret_key', 'secret-key');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => $provider_says_ok])]);

    $this->post($this->base, ['email' => 'new@example.com', 'captcha_token' => $token])
        ->assertSessionHasErrors(['email' => __('messages.org_registration.captcha_failed')]);

    Notification::assertNothingSent();
    expect(OrgUserRegistration::count())->toBe(0);
})->with([
    'no token' => [null, true],
    'the provider rejects the token' => ['bad-token', false],
]);

it('does not call the captcha provider when there is no token', function () {
    Settings::update('captcha_provider', 'turnstile');
    Http::fake();

    $this->post($this->base, ['email' => 'new@example.com'])->assertSessionHasErrors('email');

    Http::assertNothingSent();
});

// -- Step 2: the verification link ------------------------------------------------------------------

it('opens the details form for a valid link and shows the address it was sent to', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->get($this->base.'/verify/'.$registration->token)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/OrgRegisterComplete')
            ->where('email', 'new@example.com')
            ->where('token', $registration->token));
});

it('sends an expired or unknown link back to the first step with an error', function (string $which) {
    $token = 'not-a-real-token';
    if ($which === 'expired') {
        $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
        $registration->expires_at = now()->subMinute();
        $registration->save();
        $token = $registration->token;
    }

    $this->get($this->base.'/verify/'.$token)
        ->assertRedirect($this->base)
        ->assertSessionHasErrors(['email' => __('messages.org_registration.token_invalid')]);

    $this->post($this->base.'/verify/'.$token, registrationDetails())
        ->assertRedirect($this->base)
        ->assertSessionHasErrors('email');

    expect(User::where('username', 'newperson')->exists())->toBeFalse();
})->with(['expired', 'unknown']);

it('does not accept another organization\'s link', function () {
    $other = Organization::factory()->create(['slug' => 'other']);
    $registration = OrgUserRegistration::generate($other, 'new@example.com');

    $this->get($this->base.'/verify/'.$registration->token)->assertRedirect($this->base);
});

it('does not use up the link by opening it', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->get($this->base.'/verify/'.$registration->token)->assertOk();
    $this->get($this->base.'/verify/'.$registration->token)->assertOk();

    expect(OrgUserRegistration::count())->toBe(1);
});

// -- Step 2: creating the user ----------------------------------------------------------------------

it('creates the user with the verified address and sends them to set a password', function () {
    Event::fake([UserCreatedEvent::class]);
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $response = $this->post($this->base.'/verify/'.$registration->token, registrationDetails());

    $user = User::where('username', 'newperson')->firstOrFail();
    $code = NewUserCode::where('username', 'newperson')->firstOrFail();

    expect($user->email)->toBe('new@example.com')
        ->and($user->first_name)->toBe('New')
        ->and($user->last_name)->toBe('Person')
        ->and($user->name)->toBe('New Person')
        ->and($user->organization_id)->toBe($this->organization->id)
        ->and($code->organization_id)->toBe($this->organization->id)
        ->and($code->activated)->toBeFalsy();

    $response->assertSessionHasNoErrors()->assertRedirect(route('public.password.set', ['code' => $code->code]));

    Notification::assertSentTo($user, OrgRegistrationComplete::class);
    Event::assertDispatched(UserCreatedEvent::class);
});

it('uses up the link once the account exists', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    $url = $this->base.'/verify/'.$registration->token;

    $this->post($url, registrationDetails());
    expect(OrgUserRegistration::count())->toBe(0);

    $this->post($url, registrationDetails(['username' => 'another']))
        ->assertRedirect($this->base)
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'new@example.com')->count())->toBe(1)
        ->and(User::where('username', 'another')->exists())->toBeFalse();
});

it('records a user of a suborganization against that suborganization', function () {
    // A suborganization is made with its parent's plan, which the user limit is read from
    $sub = Organization::factory()->create([
        'slug' => 'subco',
        'parent_organization_id' => $this->organization->id,
        'plan_id' => $this->organization->plan_id,
    ]);
    selfRegistrationApp($sub);
    $registration = OrgUserRegistration::generate($sub, 'sub@example.com');

    $this->post('/public/org/subco/register/verify/'.$registration->token, registrationDetails(['username' => 'subuser']))
        ->assertSessionHasNoErrors();

    expect(SuborgUser::where('username', 'subuser')->sole()->organization_id)->toBe($sub->id);
});

it('does not record a top-level organization\'s user as a suborganization user', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails())->assertSessionHasNoErrors();

    expect(SuborgUser::count())->toBe(0);
});

it('validates the details and keeps the link usable', function (array $details, string|array $errors) {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, $details)->assertSessionHasErrors($errors);

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
        ->and(OrgUserRegistration::count())->toBe(1);
})->with([
    'nothing' => [[], ['username', 'first_name', 'last_name']],
    'no username' => [registrationDetails(['username' => '']), 'username'],
    'upper case username' => [registrationDetails(['username' => 'NewPerson']), 'username'],
    'username with a dot' => [registrationDetails(['username' => 'new.person']), 'username'],
    'username that is too long' => [registrationDetails(['username' => str_repeat('a', 101)]), 'username'],
    'a username that is taken' => [registrationDetails(['username' => 'demo']), 'username'],
    'no first name' => [registrationDetails(['first_name' => '']), 'first_name'],
    'no last name' => [registrationDetails(['last_name' => '']), 'last_name'],
    'a phone number that is too long' => [registrationDetails(['phone_number' => str_repeat('1', 31)]), 'phone_number'],
]);

it('does not need a phone number', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails(['phone_number' => null]))
        ->assertSessionHasNoErrors();

    expect(User::where('username', 'newperson')->exists())->toBeTrue();
});

it('takes the address from the link and not from the form', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails(['email' => 'someone-else@example.com']));

    expect(User::where('username', 'newperson')->sole()->email)->toBe('new@example.com');
});

// -- The registration record ------------------------------------------------------------------------

it('knows when a registration has expired', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    expect($registration->isExpired())->toBeFalse();

    $registration->expires_at = now()->subSecond();
    expect($registration->isExpired())->toBeTrue();
});

it('keeps one registration per address and organization', function () {
    $first = OrgUserRegistration::generate($this->organization, 'new@example.com');
    $second = OrgUserRegistration::generate($this->organization, 'new@example.com');
    OrgUserRegistration::generate($this->organization, 'other@example.com');

    expect(OrgUserRegistration::count())->toBe(2)
        ->and(OrgUserRegistration::where('token', $first->token)->exists())->toBeFalse()
        ->and(OrgUserRegistration::where('token', $second->token)->exists())->toBeTrue()
        ->and(strlen($second->token))->toBe(64);
});

// -- The set-password link ---------------------------------------------------------------------------

it('gives the set-password link the same 72 hours as an invited user', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails());

    $expires = NewUserCode::where('username', 'newperson')->firstOrFail()->expires_at;
    expect(NewUserCode::LIFETIME_HOURS)->toBe(72)
        ->and($expires)->not->toBeNull()
        ->and($expires->between(now()->addHours(71), now()->addHours(73)))->toBeTrue();
});

function expiredPasswordCode(Organization $organization, ?DateTimeInterface $expires_at): NewUserCode
{
    $code = new NewUserCode;
    $code->organization()->associate($organization);
    $code->generate('newperson');
    $code->status = 'sent';
    $code->expires_at = $expires_at;
    $code->save();

    return $code;
}

it('says so, and points to the password reset, when the set-password link has expired', function () {
    $code = expiredPasswordCode($this->organization, now()->subMinute());

    $this->get('/public/setpassword/'.$code->code)
        ->assertStatus(410)
        ->assertInertia(fn ($page) => $page
            ->component('Auth/SetPasswordExpired')
            ->where('resetUrl', route('password.request')));
});

it('does not set a password with an expired link, and sends the person to the page that explains it', function () {
    $code = expiredPasswordCode($this->organization, now()->subMinute());

    $this->post('/public/setpassword/'.$code->code.'/save', [
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertRedirect(route('public.password.set', ['code' => $code->code]));

    expect($code->fresh()->activated)->toBeFalsy();
});

it('still opens a set-password link that has no expiry, as the ones sent before this was added', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    $this->post($this->base.'/verify/'.$registration->token, registrationDetails());
    NewUserCode::where('username', 'newperson')->update(['expires_at' => null]);
    $code = NewUserCode::where('username', 'newperson')->firstOrFail()->code;

    $this->get('/public/setpassword/'.$code)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/SetPassword')->where('user.email', 'new@example.com'));
});

// -- Findings of docs/plans/self-registration.md, as the behavior we want ---------------------------

function cspDirective(?string $policy, string $directive): string
{
    preg_match('/(?:^|; )'.preg_quote($directive, '/').' ([^;]*)/', (string) $policy, $matches);

    return $matches[1] ?? '';
}

it('lets the captcha provider load its widget on the registration pages', function (string $provider, array $hosts) {
    Settings::update('captcha_provider', $provider);

    foreach ([$this->base, $this->base.'/pending'] as $url) {
        $policy = $this->get($url)->headers->get('Content-Security-Policy');

        foreach (['script-src', 'frame-src', 'connect-src'] as $directive) {
            foreach ($hosts as $host) {
                expect(cspDirective($policy, $directive))->toContain($host);
            }
        }
    }
})->with([
    'turnstile' => ['turnstile', ['https://challenges.cloudflare.com']],
    'hcaptcha' => ['hcaptcha', ['https://hcaptcha.com', 'https://*.hcaptcha.com']],
]);

it('keeps the policy as it was when no captcha is set', function () {
    $policy = $this->get($this->base)->headers->get('Content-Security-Policy');

    expect($policy)->not->toContain('cloudflare')->not->toContain('hcaptcha')
        ->and(cspDirective($policy, 'frame-src'))->toBe("'self' https://js.stripe.com https://hooks.stripe.com");
});

it('only opens the policy to the captcha provider on the registration pages', function () {
    Settings::update('captcha_provider', 'turnstile');

    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->not->toContain('challenges.cloudflare.com');
});

// -- Findings of docs/plans/self-registration.md, as the behavior we want ---------------------------

it('does not add users past the plan maximum', function () {
    // The default role gives a standard seat: a smaller one would not be held back by this
    AppRole::query()->update(['access_type' => 'standard']);
    $this->organization->plan->updateSettings(['standard.max' => 1]);
    $this->organization->plan->save();
    User::where('username', 'demo')->update(['access_type' => 'standard']);
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails())
        ->assertSessionHasErrors(['limit' => __('messages.org_registration.full')]);

    expect(User::where('username', 'newperson')->exists())->toBeFalse()
        ->and(OrgUserRegistration::where('token', $registration->token)->exists())->toBeTrue();
});

it('does not add users to a deactivated organization', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    $this->organization->status = 'deactivated';
    $this->organization->save();

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails())->assertNotFound();

    expect(User::where('username', 'newperson')->exists())->toBeFalse();
});

it('answers the same for an address that has an account and one that does not', function () {
    $known = $this->post($this->base, ['email' => 'demo@example.com']);
    RateLimiter::clear('org-register:demo:127.0.0.1');
    $unknown = $this->post($this->base, ['email' => 'nobody@example.com']);

    $known->assertSessionHasNoErrors();
    expect($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));
})->skip('Finding 4: an address that already has an account gets an error, the others get the pending page.');

it('limits how many verification emails one address receives, whatever the IP', function () {
    foreach (range(1, 6) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->post($this->base, ['email' => 'victim@example.com']);
    }

    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 3);
})->skip('Finding 5: there is no per-address limit; the number is the value phase 1 settles on.');

it('does not count a mistyped address against the limit', function () {
    foreach (range(1, 5) as $i) {
        $this->post($this->base, ['email' => 'not-an-email']);
    }

    $this->post($this->base, ['email' => 'new@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(OrgRegistrationVerification::class, 1);
})->skip('Finding 5: the attempt is counted before the address is validated.');

it('leaves no half-made user and keeps the link when creating the account fails', function () {
    $registration = OrgUserRegistration::generate($this->organization, 'new@example.com');
    Event::listen(UserCreatedEvent::class, fn () => throw new RuntimeException('directory is down'));

    $this->post($this->base.'/verify/'.$registration->token, registrationDetails());

    expect(User::where('username', 'newperson')->exists())->toBeFalse()
        ->and(OrgUserRegistration::where('token', $registration->token)->exists())->toBeTrue();
})->skip('Finding 6: the user is created before the steps that can fail, and nothing is undone.');

// Finding 7: needs the page and the resend route from phase 1.
it('says the link expired and offers a new one for the same address (finding 7)')
    ->todo();

// Finding 7: needs the prune command from phase 1.
it('removes expired registrations (finding 7)')
    ->todo();
