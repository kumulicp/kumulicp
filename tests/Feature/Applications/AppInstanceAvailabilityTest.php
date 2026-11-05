<?php

use App\AppInstance;
use App\Console\Calls\CheckUnavailableAppInstances;
use App\Console\Calls\SyncAppMonitors;
use App\Exceptions\AppUnavailableException;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Monitoring\AppMonitor;
use App\Monitoring\AppMonitors;
use App\Monitoring\Notifications\UptimeCheckFailed as AppUptimeCheckFailed;
use App\Monitoring\ResponseChecker;
use App\Notifications\AppInstanceUnavailable;
use App\Notifications\AppInstanceUnavailableWarning;
use App\Organization;
use App\Services\AppInstance\AvailabilityService;
use App\User;
use Carbon\Carbon;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\UptimeMonitor\Events\UptimeCheckFailed as UptimeCheckFailedEvent;
use Spatie\UptimeMonitor\Helpers\Period;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * When an API call to an app fails the app becomes `unavailable` and the
 * admins get one warning. The app's uptime monitor (spatie/laravel-uptime-monitor)
 * keeps checking it every 5 minutes: if it answers the app is put back, if it
 * still doesn't API calls to it are blocked and one "unavailable" notification
 * is sent. Nothing is sent after that.
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

// The app's monitor, made if the activation didn't already
function availabilityMonitor(AppInstance $app): AppMonitor
{
    $monitors = app(AppMonitors::class);

    return $monitors->monitorFor($app) ?? $monitors->sync($app) ?? throw new RuntimeException('No monitor for the app');
}

// What the monitor found when it last checked, $minutes after the app was
// marked unavailable
function monitorFound(AppInstance $app, string $status, float $minutes): AppMonitor
{
    $since = Carbon::parse($app->fresh()->setting('availability.since'));
    $monitor = availabilityMonitor($app);

    $monitor->forceFill([
        'uptime_status' => $status,
        'uptime_last_check_date' => $since->copy()->addSeconds((int) round($minutes * 60)),
        'uptime_check_times_failed_in_a_row' => $status === UptimeStatus::DOWN ? 1 : 0,
    ])->save();

    return $monitor;
}

function markUnavailable(AppInstance $app): void
{
    expect(app(AvailabilityService::class)->recordFailure($app, 'down'))->toBeTrue();
}

// ---------------------------------------------------------------------------
// The API call that finds the outage
// ---------------------------------------------------------------------------

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

it('puts the app back when a call gets through before the monitor looks', function () {
    $app = availabilityTestInstance();
    Http::fake(['*' => Http::sequence()->push('', 503)->push('<ocs><meta><status>ok</status></meta><data/></ocs>', 200)]);

    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);
    expect($app->fresh()->status)->toBe('unavailable');

    failingCall($app);

    $app->refresh();
    expect($app->status)->toBe('active')
        ->and($app->setting('availability'))->toBeNull();
});

// ---------------------------------------------------------------------------
// What the monitor finds
// ---------------------------------------------------------------------------

it('ignores what the monitor found before the app was marked unavailable', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);

    $monitor = monitorFound($app, UptimeStatus::UP, -2);

    expect(app(AvailabilityService::class)->evaluate($app->fresh(), $monitor))->toBeFalse();
    expect($app->fresh()->status)->toBe('unavailable');
});

it('puts the app back to the status it had when the monitor finds it answering', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);

    // Any answering check after the failure will do, however soon it comes
    $monitor = monitorFound($app, UptimeStatus::UP, 1);

    expect(app(AvailabilityService::class)->evaluate($app->fresh(), $monitor))->toBeTrue();

    $app->refresh();
    expect($app->status)->toBe('active')
        ->and($app->setting('availability'))->toBeNull();
    Notification::assertNotSentTo(availabilityAdminsOf($app->organization), AppInstanceUnavailable::class);
});

it('does not block on a failed check made moments after the failure', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);

    // The warning said we'd look again in 5 minutes
    $monitor = monitorFound($app, UptimeStatus::DOWN, 2);
    app(AvailabilityService::class)->evaluate($app->fresh(), $monitor);

    expect($app->fresh()->isApiBlocked())->toBeFalse();
    Notification::assertNotSentTo(availabilityAdminsOf($app->organization), AppInstanceUnavailable::class);
});

it('blocks API calls and sends the unavailable notification once when the recheck fails', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);
    $admins = availabilityAdminsOf($app->organization);
    $service = app(AvailabilityService::class);

    $monitor = monitorFound($app, UptimeStatus::DOWN, 5);
    expect($service->evaluate($app->fresh(), $monitor))->toBeFalse();

    $app->refresh();
    expect($app->status)->toBe('unavailable')
        ->and($app->isApiBlocked())->toBeTrue();
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);

    // Blocked: the call never leaves
    Http::fake();
    expect(fn () => failingCall($app))->toThrow(AppUnavailableException::class);
    Http::assertNothingSent();

    // The monitor keeps failing, and nothing more is sent however often
    foreach ([10, 15, 20] as $minutes) {
        $service->evaluate($app->fresh(), monitorFound($app, UptimeStatus::DOWN, $minutes));
    }

    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailableWarning::class, 1);
});

it('lets API calls through again once the monitor finds a blocked app answering', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);
    $service = app(AvailabilityService::class);
    $service->evaluate($app->fresh(), monitorFound($app, UptimeStatus::DOWN, 5));
    expect($app->fresh()->isApiBlocked())->toBeTrue();

    expect($service->evaluate($app->fresh(), monitorFound($app, UptimeStatus::UP, 10)))->toBeTrue();

    $app->refresh();
    expect($app->status)->toBe('active')
        ->and($app->setting('availability'))->toBeNull();

    Http::fake(['*' => Http::response('<ocs><meta><status>ok</status></meta><data/></ocs>', 200)]);
    failingCall($app);
    Http::assertSentCount(1);
});

it('starts over with a new warning if an app that came back goes down again', function () {
    $app = availabilityTestInstance();
    $service = app(AvailabilityService::class);

    markUnavailable($app);
    $service->evaluate($app->fresh(), monitorFound($app, UptimeStatus::UP, 1));
    markUnavailable($app->fresh());

    Notification::assertSentToTimes(availabilityAdminsOf($app->organization)->first(), AppInstanceUnavailableWarning::class, 2);
});

it('acts on the monitors on schedule, without any HTTP of its own', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);
    Http::fake();

    (new CheckUnavailableAppInstances)();
    expect($app->fresh()->isApiBlocked())->toBeFalse();

    monitorFound($app, UptimeStatus::DOWN, 6);
    (new CheckUnavailableAppInstances)();
    expect($app->fresh()->isApiBlocked())->toBeTrue();

    monitorFound($app, UptimeStatus::UP, 11);
    (new CheckUnavailableAppInstances)();
    expect($app->fresh()->status)->toBe('active');

    Http::assertNothingSent();
});

it('ignores apps that are not unavailable, whatever their monitor says', function () {
    $app = availabilityTestInstance();
    availabilityMonitor($app)->forceFill([
        'uptime_status' => UptimeStatus::DOWN,
        'uptime_last_check_date' => now(),
    ])->save();

    (new CheckUnavailableAppInstances)();

    Notification::assertNothingSent();
    expect($app->fresh()->status)->toBe('active');
});

it('makes the monitor for an app that was marked before it had one', function () {
    $app = availabilityTestInstance();
    markUnavailable($app);
    AppMonitor::where('app_instance_id', $app->id)->delete();

    (new CheckUnavailableAppInstances)();

    expect(AppMonitor::where('app_instance_id', $app->id)->count())->toBe(1);
});

it('goes from a failed API call to blocked through the real monitor check', function () {
    $app = availabilityTestInstance();
    $monitor = availabilityMonitor($app);
    expect($monitor->uptime_check_enabled)->toBeTrue();

    // What the package's own client gets back (it doesn't use the Http facade)
    config(['uptime-monitor.uptime_check.guzzle_options.handler' => HandlerStack::create(
        new MockHandler([new Response(503), new Response(503), new Response(200)])
    )]);

    Http::fake(['*' => Http::response('', 503)]);
    expect(fn () => failingCall($app))->toThrow(ConnectionFailedException::class);
    $admins = availabilityAdminsOf($app->organization);

    // First check, made within 5 minutes of the failure: down, but not yet
    Artisan::call('monitor:check-uptime');
    (new CheckUnavailableAppInstances)();
    expect($monitor->fresh()->uptime_status)->toBe(UptimeStatus::DOWN)
        ->and($app->fresh()->isApiBlocked())->toBeFalse();

    // Monitors that are down are checked on their interval, not every minute
    $this->travel(2)->minutes();
    expect($monitor->fresh()->shouldCheckUptime())->toBeFalse();

    // The recheck after 5 minutes: still down
    $this->travel(3)->minutes();
    Artisan::call('monitor:check-uptime');
    (new CheckUnavailableAppInstances)();
    expect($app->fresh()->isApiBlocked())->toBeTrue();
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);

    // The package's own "went down" email is not sent for an app
    Notification::assertNotSent(AppUptimeCheckFailed::class);

    // And back
    $this->travel(5)->minutes();
    Artisan::call('monitor:check-uptime');
    (new CheckUnavailableAppInstances)();
    expect($app->fresh()->status)->toBe('active');
    Notification::assertSentToTimes($admins->first(), AppInstanceUnavailable::class, 1);
});

// ---------------------------------------------------------------------------
// The monitors
// ---------------------------------------------------------------------------

it('creates the monitor when the app becomes active, and only checks it while it is active or unavailable', function () {
    $app = availabilityTestInstance();
    $app->status = 'activating';
    $app->save();
    AppMonitor::where('app_instance_id', $app->id)->delete();

    // Not monitored while activating
    $app->status = 'updating';
    $app->save();
    expect(AppMonitor::where('app_instance_id', $app->id)->count())->toBe(0);

    $app->status = 'active';
    $app->save();

    $monitor = AppMonitor::where('app_instance_id', $app->id)->firstOrFail();
    expect($monitor->uptime_check_enabled)->toBeTrue()
        ->and((string) $monitor->url)->toBe($app->address())
        ->and((int) $monitor->uptime_check_interval_in_minutes)->toBe(AvailabilityService::RECHECK_MINUTES)
        ->and($monitor->certificate_check_enabled)->toBe(str_starts_with($app->address(), 'https://'));

    foreach (['updating', 'deactivating', 'deactivated'] as $status) {
        $app->status = $status;
        $app->save();
        expect($monitor->fresh()->uptime_check_enabled)->toBeFalse("monitoring while {$status}");
    }

    // Results from before it was switched off say nothing about the app now
    $monitor->forceFill(['uptime_status' => UptimeStatus::DOWN, 'uptime_check_times_failed_in_a_row' => 3])->save();
    $app->status = 'active';
    $app->save();

    $monitor->refresh();
    expect($monitor->uptime_check_enabled)->toBeTrue()
        ->and($monitor->uptime_status)->toBe(UptimeStatus::NOT_YET_CHECKED)
        ->and($monitor->uptime_check_times_failed_in_a_row)->toBe(0);
});

it('keeps checking an app that is unavailable', function () {
    $app = availabilityTestInstance();
    $monitor = availabilityMonitor($app);

    markUnavailable($app);

    expect($monitor->fresh()->uptime_check_enabled)->toBeTrue();
});

it('moves the monitor when the app changes address, and removes it with the app', function () {
    $app = availabilityTestInstance();
    $child = test()->makeChildInstance($app, 'monitored-child');
    $monitor = AppMonitor::where('app_instance_id', $child->id)->firstOrFail();
    expect((string) $monitor->url)->toBe($child->address());

    $monitor->forceFill(['uptime_status' => UptimeStatus::UP, 'uptime_last_check_date' => now()])->save();
    $child->name = 'renamed-child';
    $child->save();

    $monitor->refresh();
    expect((string) $monitor->url)->toBe($child->fresh()->address())
        ->and($monitor->uptime_status)->toBe(UptimeStatus::NOT_YET_CHECKED);

    $child->delete();
    expect(AppMonitor::where('app_instance_id', $child->id)->count())->toBe(0);
});

it('gives an app that lives at its parent address no monitor of its own, and uses the parent\'s', function () {
    $hub = availabilityTestInstance();
    $hub->application->domain_option = ['parent'];
    $hub->application->save();

    $child = test()->makeChildInstance($hub->fresh(), 'shared-child');

    expect(AppMonitor::where('app_instance_id', $child->id)->count())->toBe(0)
        ->and(app(AppMonitors::class)->monitorFor($child->fresh())?->app_instance_id)->toBe($hub->id);
});

it('leaves a monitor someone added by hand at the same address alone', function () {
    $app = availabilityTestInstance();
    AppMonitor::where('app_instance_id', $app->id)->delete();
    $manual = AppMonitor::create(['url' => $app->address()]);

    app(AppMonitors::class)->sync($app);

    expect($manual->fresh()->app_instance_id)->toBeNull()
        ->and(AppMonitor::count())->toBe(1);
});

it('reconciles: makes the missing monitors, fixes addresses, and removes strays', function () {
    $app = availabilityTestInstance();
    AppMonitor::where('app_instance_id', $app->id)->delete();
    $stray = AppMonitor::create(['url' => 'http://gone.example', 'app_instance_id' => 99999]);
    $manual = AppMonitor::create(['url' => 'http://manual.example']);

    (new SyncAppMonitors)();

    expect(AppMonitor::where('app_instance_id', $app->id)->count())->toBe(1)
        ->and(AppMonitor::find($stray->id))->toBeNull()
        ->and(AppMonitor::find($manual->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// How the package is set up
// ---------------------------------------------------------------------------

it('checks an app that is down on its interval, not every minute like the package does for other monitors', function () {
    $app = availabilityTestInstance();
    $monitor = availabilityMonitor($app);
    $by_hand = AppMonitor::create(['url' => 'http://manual.example'])->fresh(); // fresh: for the column defaults

    foreach ([$monitor, $by_hand] as $m) {
        $m->forceFill(['uptime_status' => UptimeStatus::DOWN, 'uptime_last_check_date' => now()->subMinutes(2)])->save();
    }

    expect($monitor->shouldCheckUptime())->toBeFalse()
        ->and($by_hand->shouldCheckUptime())->toBeTrue();

    $monitor->forceFill(['uptime_last_check_date' => now()->subMinutes(5)])->save();
    expect($monitor->shouldCheckUptime())->toBeTrue();
});

it('judges answers: for an app only a gateway error is down, for any other monitor any 4xx/5xx', function (int $status, bool $app_is_up, bool $other_is_up) {
    $checker = new ResponseChecker;
    $response = new Response($status);

    $app_monitor = new AppMonitor(['url' => 'http://app.example', 'app_instance_id' => 1]);
    $other = new AppMonitor(['url' => 'http://other.example']);

    expect($checker->isValidResponse($response, $app_monitor))->toBe($app_is_up)
        ->and($checker->isValidResponse($response, $other))->toBe($other_is_up);
})->with([
    '200' => [200, true, true],
    '302' => [302, true, true],
    '401' => [401, true, false],
    '404' => [404, true, false],
    '500' => [500, true, false],
    '502' => [502, false, false],
    '503' => [503, false, false],
    '504' => [504, false, false],
]);

it('still looks for the monitor\'s string', function () {
    $monitor = new AppMonitor(['url' => 'http://app.example', 'app_instance_id' => 1, 'look_for_string' => 'installed']);

    expect((new ResponseChecker)->isValidResponse(new Response(200, [], '{"installed":true}'), $monitor))->toBeTrue()
        ->and((new ResponseChecker)->isValidResponse(new Response(200, [], 'maintenance'), $monitor))->toBeFalse();
});

it('drops the package\'s down and recovered emails for an app, but not for a monitor added by hand', function () {
    $period = new Period(now()->subMinutes(10), now());

    $app_monitor = new AppMonitor(['url' => 'http://app.example', 'app_instance_id' => 1, 'uptime_status' => UptimeStatus::DOWN]);
    $other = new AppMonitor(['url' => 'http://other.example', 'uptime_status' => UptimeStatus::DOWN]);

    $failed = fn (AppMonitor $monitor) => (new AppUptimeCheckFailed)
        ->setEvent(new UptimeCheckFailedEvent($monitor, $period))
        ->isStillRelevant();

    expect($failed($app_monitor))->toBeFalse()
        ->and($failed($other))->toBeTrue();
});

it('is wired into the package', function () {
    expect(config('uptime-monitor.monitor_model'))->toBe(AppMonitor::class)
        ->and(config('uptime-monitor.uptime_check.response_checker'))->toBe(ResponseChecker::class)
        ->and(config('uptime-monitor.uptime_check.guzzle_options.http_errors'))->toBeFalse()
        ->and(array_keys(config('uptime-monitor.notifications.notifications')))->toContain(AppUptimeCheckFailed::class);
});

// ---------------------------------------------------------------------------
// Who is told
// ---------------------------------------------------------------------------

it('notifies the system admins and the organization admins', function () {
    $template = availabilityTestInstance();
    [$app, $organization, $customer_admin] = availabilityCustomerApp($template);

    $system_admins = availabilityAdminsOf(Organization::find(1));
    expect($system_admins)->not->toBeEmpty();

    markUnavailable($app);
    app(AvailabilityService::class)->evaluate($app->fresh(), monitorFound($app, UptimeStatus::DOWN, 5));

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
