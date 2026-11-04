<?php

use App\AppInstance;
use App\Console\Calls\CheckUnavailableAppInstances;
use App\Exceptions\AppUnavailableException;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Notifications\AppInstanceUnavailable;
use App\Notifications\AppInstanceUnavailableWarning;
use App\Organization;
use App\Services\AppInstance\AvailabilityService;
use App\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * When an API call to an app fails the app becomes `unavailable` and the
 * admins get one warning. A recheck every 5 minutes either puts it back or,
 * if it still doesn't answer, blocks API calls to it and sends one
 * "unavailable" notification. Nothing is sent after that.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

function availabilityTestInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return test()->runActivate($plan, Organization::find(1), $app);
}

// A customer organization of the control panel (org 1 is the super account),
// with an app of its own and one admin
function availabilityCustomerApp(AppInstance $template): array
{
    $organization = Organization::factory()->create([
        'slug' => 'customer',
        'parent_organization_id' => 1,
        'type' => 'nonprofit',
    ]);

    $admin = User::factory()->create(['organization_id' => $organization->id, 'username' => 'customeradmin']);
    Role::findOrCreate('organization_admin');
    $admin->assignRole('organization_admin');

    $app = test()->makeChildInstance($template, 'customer-app');
    $app->parent_id = null;
    $app->organization_id = $organization->id;
    $app->save();

    return [$app, $organization, $admin];
}

// The admins' own user records (what a notification is addressed to), not the
// account manager's wrappers around them
function availabilityAdminsOf(Organization $organization)
{
    return collect($organization->admins())->map(fn ($admin) => $admin->get());
}

function failingCall(AppInstance $app): void
{
    (new Users($app))->find('someone');
}

it('marks an active app unavailable when an API call gets a gateway error, and warns once', function () {
    $app = availabilityTestInstance();
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);

    $app->refresh();
    expect($app->status)->toBe('unavailable')
        ->and($app->setting('availability.previous_status'))->toBe('active')
        ->and($app->isApiBlocked())->toBeFalse();

    $admins = availabilityAdminsOf($app->organization);
    Notification::assertSentTo($admins, AppInstanceUnavailableWarning::class);
    Notification::assertNotSentTo($admins, AppInstanceUnavailable::class);

    // Still failing, not blocked yet: calls go out, but nothing more is sent
    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailableWarning::class, 1);
});

it('treats a connection that cannot be made as a failure too', function () {
    $app = availabilityTestInstance();
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    // Retryable, so a task running the call goes back to pending
    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);

    expect($app->fresh()->status)->toBe('unavailable');
    Notification::assertSentTo(availabilityAdminsOf($app->organization), AppInstanceUnavailableWarning::class);
});

it('does not treat an app error as the app being down', function () {
    $app = availabilityTestInstance();
    Http::fake(['*' => Http::response('', 500)]);

    try {
        failingCall($app);
    } catch (Throwable) {
        // the 500 is reported to the caller as before
    }

    expect($app->fresh()->status)->toBe('active');
    Notification::assertNothingSent();
});

it('leaves an app that is not active alone, since its API is expected not to answer', function (string $status) {
    $app = availabilityTestInstance();
    $app->status = $status;
    $app->save();

    expect(app(AvailabilityService::class)->recordFailure($app, 'down'))->toBeFalse();

    expect($app->fresh()->status)->toBe($status);
    Notification::assertNothingSent();
})->with(['activating', 'updating', 'deactivating', 'deactivated']);

it('puts the app back when a call gets through before the recheck', function () {
    $app = availabilityTestInstance();
    Http::fake(['*' => Http::sequence()->push('', 503)->push('<ocs><meta><status>ok</status></meta><data/></ocs>', 200)]);

    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);
    expect($app->fresh()->status)->toBe('unavailable');

    failingCall($app);

    $app->refresh();
    expect($app->status)->toBe('active')
        ->and($app->setting('availability'))->toBeNull();
});

it('is not due for a recheck until 5 minutes have passed', function () {
    $app = availabilityTestInstance();
    $service = app(AvailabilityService::class);
    $service->recordFailure($app, 'down');

    expect($service->isDueForRecheck($app))->toBeFalse();

    $this->travel(4)->minutes();
    expect($service->isDueForRecheck($app))->toBeFalse();

    $this->travel(2)->minutes();
    expect($service->isDueForRecheck($app))->toBeTrue();
});

it('blocks API calls and sends the unavailable notification once when the recheck fails', function () {
    $app = availabilityTestInstance();
    $service = app(AvailabilityService::class);
    $service->recordFailure($app, 'down');
    $admins = availabilityAdminsOf($app->organization);

    expect($service->recheck($app, fn () => false))->toBeFalse();

    $app->refresh();
    expect($app->status)->toBe('unavailable')
        ->and($app->isApiBlocked())->toBeTrue();
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);

    // Blocked: the call never leaves
    Http::fake();
    expect(fn () => failingCall($app))->toThrow(AppUnavailableException::class);
    Http::assertNothingSent();

    // Keeps checking, but sends nothing more, however many times it fails
    $service->recheck($app, fn () => false);
    $service->recheck($app, fn () => false);

    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailableWarning::class, 1);
});

it('puts the app back to the status it had when the recheck finds it answering', function () {
    $app = availabilityTestInstance();
    $service = app(AvailabilityService::class);
    $service->recordFailure($app, 'down');
    $service->recheck($app, fn () => false);
    expect($app->fresh()->isApiBlocked())->toBeTrue();

    expect($service->recheck($app, fn () => true))->toBeTrue();

    $app->refresh();
    expect($app->status)->toBe('active')
        ->and($app->setting('availability'))->toBeNull();

    // API calls flow again
    Http::fake(['*' => Http::response('<ocs><meta><status>ok</status></meta><data/></ocs>', 200)]);
    failingCall($app);
    Http::assertSentCount(1);
});

it('starts over with a new warning if an app that came back goes down again', function () {
    $app = availabilityTestInstance();
    $service = app(AvailabilityService::class);

    $service->recordFailure($app, 'down');
    $service->recheck($app, fn () => true);
    $service->recordFailure($app, 'down again');

    Notification::assertSentToTimes(availabilityAdminsOf($app->organization)->first(), AppInstanceUnavailableWarning::class, 2);
});

// null: nothing answers (refused / timed out); otherwise the HTTP status
it('probes the app over HTTP; a gateway error or no connection is down, anything else is up', function (?int $status, bool $up) {
    $app = availabilityTestInstance();
    Http::fake($status === null
        ? fn () => throw new ConnectionException('refused')
        : ['*' => Http::response('', $status)]);

    expect((new App\Integrations\Applications\Application($app))->isReachable())->toBe($up);
})->with([
    'refused' => [null, false],
    '502' => [502, false],
    '503' => [503, false],
    '504' => [504, false],
    '200' => [200, true],
    '404' => [404, true],
    '500' => [500, true],
]);

it('rechecks unavailable apps on schedule, but only once the last check is 5 minutes old', function () {
    $app = availabilityTestInstance();
    app(AvailabilityService::class)->recordFailure($app, 'down');

    // Http::fake() adds to the stubs already there (the first match wins), so
    // what the app answers is changed through this, not by faking again
    $status = 503;
    Http::fake(fn () => Http::response('', $status));

    (new CheckUnavailableAppInstances)();
    Http::assertNothingSent();
    expect($app->fresh()->isApiBlocked())->toBeFalse();

    $this->travel(5)->minutes();
    (new CheckUnavailableAppInstances)();
    Http::assertSentCount(1);
    expect($app->fresh()->isApiBlocked())->toBeTrue();

    // The next check is 5 minutes after this one, and finds the app back
    $status = 200;
    $this->travel(4)->minutes();
    (new CheckUnavailableAppInstances)();
    Http::assertSentCount(1);
    expect($app->fresh()->isApiBlocked())->toBeTrue();

    $this->travel(2)->minutes();
    (new CheckUnavailableAppInstances)();
    Http::assertSentCount(2);
    expect($app->fresh()->status)->toBe('active');
});

it('ignores apps that are not unavailable', function () {
    availabilityTestInstance();
    Http::fake();

    (new CheckUnavailableAppInstances)();

    Http::assertNothingSent();
});

it('notifies the system admins and the organization admins', function () {
    $template = availabilityTestInstance();
    [$app, $organization, $customer_admin] = availabilityCustomerApp($template);

    $system_admins = availabilityAdminsOf(Organization::find(1));
    expect($system_admins)->not->toBeEmpty();

    $service = app(AvailabilityService::class);
    $service->recordFailure($app, 'cURL error 7 at https://customer.example');
    $service->recheck($app, fn () => false);

    foreach ([AppInstanceUnavailableWarning::class, AppInstanceUnavailable::class] as $notification) {
        Notification::assertSentTo($system_admins, $notification);
        Notification::assertSentTo($customer_admin, $notification);
    }
});

it('shows the technical reason to system admins only', function () {
    $template = availabilityTestInstance();
    [$app, $organization, $customer_admin] = availabilityCustomerApp($template);

    app(AvailabilityService::class)->recordFailure($app, 'cURL error 7: connection refused');

    $reasons = [];
    Notification::assertSentTo(
        [availabilityAdminsOf(Organization::find(1))->first(), $customer_admin],
        AppInstanceUnavailableWarning::class,
        function ($notification, $channels, $notifiable) use (&$reasons) {
            $reasons[] = str_contains(
                (string) $notification->toMail($notifiable)->render(),
                'connection refused'
            );

            return true;
        }
    );

    // Order of sending is system admins, then the organization's
    expect($reasons)->toBe([true, false]);
});

it('sends to the admins once when the app belongs to the super account itself', function () {
    $app = availabilityTestInstance();

    app(AvailabilityService::class)->recordFailure($app, 'down');

    Notification::assertSentToTimes(availabilityAdminsOf($app->organization)->first(), AppInstanceUnavailableWarning::class, 1);
});
