<?php

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\AppRole;
use App\Enums\TaskStatus;
use App\Integrations\Applications\AppFeature;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Task;
use App\Updates\UpdateDrift;
use App\Updates\UpdateStep;
use App\Updates\UpdateSteps;
use App\UpdateState;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * What an app instance is out of date for: each aspect works out a fingerprint
 * from the definitions it depends on, which is stored when a step for it has
 * run. Only changes to what an aspect depends on make the instance out of
 * date, and only for the steps that go with it.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

// Brings the 'settings' aspect up to date; can change the plan while it runs
class DriftRecordingStep extends UpdateStep
{
    public static bool $fail = false;

    public static ?Closure $while_running = null;

    public function key(): string
    {
        return 'drift-recording';
    }

    public function label(): string
    {
        return 'Drift recording';
    }

    public function aspects(): array
    {
        return ['settings'];
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        if (self::$while_running) {
            (self::$while_running)($app_instance);
        }

        return self::$fail ? ActionResult::fail('nope') : ActionResult::done();
    }
}

function driftInstance(): AppInstance
{
    DriftRecordingStep::$fail = false;
    DriftRecordingStep::$while_running = null;
    app(UpdateSteps::class)->register(new DriftRecordingStep);

    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return test()->runActivate($plan, Organization::find(1), $app);
}

function driftNow(AppInstance $instance): array
{
    return app(UpdateDrift::class)->drift($instance->fresh());
}

/** Runs an update of the steps and each step task */
function runDriftSteps(AppInstance $instance, array $keys): void
{
    Queue::fake();

    $update = Action::execute(new ApplicationUpdate($instance->fresh(), $keys));
    app(TaskRunner::class)->execute($update);
    $update->status = TaskStatus::IN_PROGRESS;
    $update->save();

    foreach (Task::where('action_slug', 'application_update_step')->orderBy('id')->get() as $step_task) {
        app(TaskRunner::class)->execute($step_task);
    }
}

it('works out the same fingerprints every time', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);

    expect($drift->current($instance->fresh()))->toBe($drift->current($instance->fresh()))
        ->and(array_keys($drift->current($instance->fresh())))->toContain('release', 'settings');
});

it('does not flag an aspect nothing has been stored for, and does once it is taken as up to date', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);

    editPlan($instance->plan, 5);

    expect($drift->drift($instance->fresh()))->toBe([])
        ->and($drift->unknown($instance->fresh()))->toContain('release', 'settings');

    $stored = $drift->baseline($instance->fresh());

    expect($stored)->toContain('release', 'settings')
        ->and($drift->unknown($instance->fresh()))->toBe([])
        // nothing left to store
        ->and($drift->baseline($instance->fresh()))->toBe([])
        ->and($drift->drift($instance->fresh()))->toBe([]);
});

it('does not flag a change to what no aspect depends on', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    $plan = $instance->plan;
    $plan->name = 'Renamed';
    $plan->description = 'Something else';
    $plan->display_order = 99;
    $plan->save();

    $version = $instance->version;
    $version->updateSettings(['update_check' => ['checked_at' => now()->toIso8601String()]]);

    // What the release and the single sign-on write back themselves
    $instance->fresh()->updateSetting('sso', ['id' => 1]);
    $instance->fresh()->updateSetting('override.pvc.name', 'claim');
    $instance->fresh()->updateSetting('storage_quota', 100);

    expect(driftNow($instance))->toBe([]);
});

it('does not flag the plan settings that only matter to kumulicp', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    $plan = $instance->plan;
    $plan->updateSettings([
        // validations of what the organization may add
        'base.max' => 3, 'standard.max' => 3, 'basic.max' => 3, 'storage.max' => 3,
        // what is shown, and decided when the plan is chosen
        'basic.name' => 'Guests', 'admin_access' => true, 'self_registration_enabled' => true,
        'expires_after' => 30, 'trial_for' => 14, 'server_type' => 'separate', 'parent_server_id' => 4,
    ]);
    $plan->domain_enabled = ! $plan->domain_enabled;
    $plan->domain_max = 5;
    $plan->features = ['shown' => true];
    $plan->save();

    expect(driftNow($instance))->toBe([]);
});

it('flags the storage a plan gives for the release only', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    foreach (['base.storage', 'standard.storage', 'basic.storage'] as $n => $setting) {
        $instance->plan->updateSettings([$setting => 10 + $n]);
        $instance->plan->save();

        expect(driftNow($instance))->toBe(['release'], $setting);

        app(UpdateDrift::class)->dismiss($instance->fresh(), ['release']);
    }
});

it('flags the prices of a plan, and what they are charged for, for the subscription only', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());
    $plan = $instance->plan;

    foreach ([
        ['base.prices.USD.amount' => 12],
        ['standard.prices.USD.price_id' => 'price_123'],
        ['basic.amount' => 5],
        ['features.extra.prices.USD.amount' => 3],
    ] as $change) {
        $plan->updateSettings($change);
        $plan->save();

        expect(driftNow($instance))->toBe(['subscription'], json_encode($change));

        app(UpdateDrift::class)->dismiss($instance->fresh(), ['subscription']);
    }

    $plan->payment_enabled = ! $plan->payment_enabled;
    $plan->save();

    expect(driftNow($instance))->toBe(['subscription'])
        ->and(app(UpdateDrift::class)->stepsToRun($instance->fresh()))->toBe(['subscription']);
});

it('flags a change to the plan for the aspects that depend on it, and only the steps that go with them', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    editPlan($instance->plan, 5);

    expect(driftNow($instance))->toContain('release', 'settings')
        ->and(app(UpdateDrift::class)->stepsToRun($instance->fresh()))->toContain('release', 'settings-job', 'drift-recording');
});

it('flags a configuration for the release, unless it names another aspect', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);
    $drift->baseline($instance->fresh());

    $plan = $instance->plan;
    $plan->updateSettings(['configurations.replicaCount' => 3]);
    $plan->save();

    expect(driftNow($instance))->toBe(['release']);

    // A configuration says which aspect it belongs to in the plan's additional configurations too
    $drift->dismiss($instance->fresh(), ['release']);
    $plan->updateSettings(['additionalConfigs.greeting' => ['aspect' => 'settings']]);
    $plan->save();
    $drift->dismiss($instance->fresh(), ['release', 'settings']);

    $plan->updateSettings(['configurations.greeting' => 'hello']);
    $plan->save();

    expect(driftNow($instance))->toBe(['settings']);
});

it('flags a feature for the settings, and one with an action only for the aspect of its own', function () {
    $instance = driftInstance();
    $profile = Application::profile($instance->application);
    // A feature with an action (the Nextcloud addons are managed through the API) has an aspect of its own
    $profile->addFeature(new class extends AppFeature
    {
        public $name = 'plain';
    });
    $profile->addFeature(new class extends AppFeature
    {
        public $name = 'managed';

        public $action = stdClass::class;
    });
    $drift = app(UpdateDrift::class);
    $drift->baseline($instance->fresh());

    $plan = $instance->plan;
    $plan->updateSettings(['features.plain.status' => 'enabled']);
    $plan->save();

    expect(driftNow($instance))->toBe(['settings']);

    $drift->dismiss($instance->fresh(), ['settings']);
    $plan->updateSettings(['features.managed.status' => 'enabled', 'features.managed.price' => 5]);
    $plan->save();

    expect(driftNow($instance))->toContain('feature.managed')->not->toContain('settings');

    // What the feature costs says nothing about the app, only about the subscription
    $drift->dismiss($instance->fresh(), ['feature.managed', 'subscription']);
    $plan->updateSettings(['features.managed.price' => 6, 'features.plain.price' => 6]);
    $plan->save();

    expect(driftNow($instance))->toBe(['subscription']);
});

it('does not flag a change to the plan for the roles', function () {
    config(['account_manager.driver' => 'ldap']);
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    editPlan($instance->plan, 5);

    expect(driftNow($instance))->not->toContain('roles');
});

it('flags a change to a role for the roles, and not for the release', function () {
    config(['account_manager.driver' => 'ldap']);
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    $role = AppRole::where('application_id', $instance->application_id)->firstOrFail();
    $role->ignore_role = ! $role->ignore_role;
    $role->save();

    expect(driftNow($instance))->toBe(['roles'])
        ->and(app(UpdateDrift::class)->stepsToRun($instance->fresh()))->toBe(['ldap-groups']);
});

it('flags a change to what the version is released with, for the release only', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());

    $instance->version->updateSettings(['chart_version' => '9.9.9']);

    expect(driftNow($instance))->toBe(['release']);
});

it('stores what a step brought up to date when it has run, and leaves the other aspects alone', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);
    $drift->baseline($instance->fresh());
    editPlan($instance->plan, 5);

    runDriftSteps($instance, ['drift-recording']);

    // 'settings' is up to date, 'release' still is not: no step for it has run
    expect(driftNow($instance))->toBe(['release'])
        ->and(UpdateState::where('app_instance_id', $instance->id)->where('aspect', 'settings')->first()->task_id)->not->toBeNull();
});

it('does not store anything for a step that failed', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());
    editPlan($instance->plan, 5);
    DriftRecordingStep::$fail = true;

    runDriftSteps($instance, ['drift-recording']);

    expect(driftNow($instance))->toContain('settings');
});

it('is still out of date when the definitions changed while the step was running', function () {
    $instance = driftInstance();
    app(UpdateDrift::class)->baseline($instance->fresh());
    editPlan($instance->plan, 5);
    DriftRecordingStep::$while_running = fn (AppInstance $app) => editPlan($app->plan, 6);

    runDriftSteps($instance, ['drift-recording']);

    expect(driftNow($instance))->toContain('settings');
});

it('takes an aspect as up to date, without running anything, when it is dismissed', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);
    $drift->baseline($instance->fresh());
    editPlan($instance->plan, 5);

    $drift->dismiss($instance->fresh(), ['settings']);

    expect(driftNow($instance))->toBe(['release'])
        ->and(Task::where('action_slug', 'application_update_step')->exists())->toBeFalse();
});

it('runs no steps for an aspect no step names', function () {
    $instance = driftInstance();
    $drift = app(UpdateDrift::class);
    $drift->baseline($instance->fresh());
    editPlan($instance->plan, 5);

    // A step that names no aspects is only ever run when an admin asks for it
    app(UpdateSteps::class)->register(new class extends UpdateStep
    {
        public function key(): string
        {
            return 'manual';
        }

        public function label(): string
        {
            return 'Manual';
        }
    });

    expect($drift->stepsToRun($instance->fresh()))->not->toContain('manual');
});

it('stores the baseline for the instances that have nothing stored with a command', function () {
    $instance = driftInstance();

    $this->artisan('updates:baseline')->assertSuccessful();

    expect(UpdateState::where('app_instance_id', $instance->id)->count())->toBeGreaterThan(0)
        ->and(app(UpdateDrift::class)->unknown($instance->fresh()))->toBe([]);
});
