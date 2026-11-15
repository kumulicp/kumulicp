<?php

use App\Actions\Apps\MassReconcile;
use App\AppInstance;
use App\DefinitionChange;
use App\Enums\TaskStatus;
use App\Jobs\Updates\ScanInstancesForDrift;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Task;
use App\Updates\AutoUpdates;
use App\Updates\Reconcile;
use App\Updates\UpdateDrift;
use App\Updates\UpdatePolicy;
use App\UpdateState;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;
use Tests\TestCase;

/**
 * Bringing app instances up to date for what changed, and nothing else: a few
 * first and then the rest in batches, each once the one before has gone well, and
 * on their own for what an admin has said so for.
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

/**
 * An app instance, and as many more of its plan as asked for, all taken as up to date
 *
 * @return Collection<int, AppInstance>
 */
function rolloutInstances(int $more = 0): Collection
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    $first = test()->runActivate($plan, Organization::find(1), $app);
    $instances = collect([$first]);

    for ($i = 0; $i < $more; $i++) {
        $instances->push(AppInstance::factory()->create([
            'organization_id' => $first->organization_id,
            'application_id' => $first->application_id,
            'version_id' => $first->version_id,
            'plan_id' => $first->plan_id,
            'web_server_id' => $first->web_server_id,
            'status' => 'active',
        ]));
    }

    $instances->each(fn (AppInstance $instance) => app(UpdateDrift::class)->baseline($instance->fresh()));
    DefinitionChange::query()->delete();

    return $instances->map(fn (AppInstance $instance) => $instance->fresh());
}

/** The updates that were started, with the steps of each */
function startedUpdates(): array
{
    return Task::where('action_slug', 'application_update')->orderBy('id')->get()
        ->map(fn (Task $task) => ['instance' => $task->app_instance_id, 'steps' => $task->getValue('steps')])
        ->all();
}

function rolloutAdmin(): User
{
    return User::find(1);
}

// Which go first

it('updates a few first, from as many different apps as there are, and the rest in batches', function () {
    $instance = fn (int $id, int $application) => (new AppInstance)->forceFill(['id' => $id, 'application_id' => $application]);
    $instances = collect([$instance(1, 10), $instance(2, 10), $instance(3, 20), $instance(4, 10), $instance(5, 10)]);
    $reconcile = app(Reconcile::class);

    expect($reconcile->waves($instances, 2, 2))->toBe([[1, 3], [2, 4], [5]])
        ->and($reconcile->waves($instances, 0, 2))->toBe([[1, 2], [3, 4], [5]])
        ->and($reconcile->waves($instances, 1, 100))->toBe([[1], [2, 3, 4, 5]])
        // more than there are is all of them
        ->and($reconcile->waves($instances, 10, 2))->toHaveCount(1)
        ->and($reconcile->waves(collect(), 1, 10))->toBe([]);
});

// What would be updated

it('plans the steps for what is out of date and leaves out an app that is busy', function () {
    $instance = rolloutInstances()->first();
    $reconcile = app(Reconcile::class);

    expect($reconcile->plan($instance))->toBe(['steps' => [], 'skipped' => Reconcile::NOTHING_TO_DO]);

    editPlan($instance->plan, 5);

    expect($reconcile->plan($instance->fresh())['steps'])->toContain('release', 'settings-job')
        ->and($reconcile->plan($instance->fresh(), ['settings'])['steps'])->toBe(['settings-job']);

    $instance->status = 'updating';
    $instance->save();

    expect($reconcile->plan($instance->fresh()))->toBe(['steps' => [], 'skipped' => Reconcile::NOT_ACTIVE]);
});

it('says what an update would run, and whether any of it is disruptive, before it is started', function () {
    $instances = rolloutInstances(2);
    editPlan($instances->first()->plan, 5);
    AppInstance::whereKey($instances->last()->id)->update(['status' => 'deleting']);

    $preview = app(Reconcile::class)->preview($instances->pluck('id')->all(), null, 1, 5);
    $steps = collect($preview['steps'])->keyBy('key');

    expect($preview['instances'])->toBe(2)
        ->and($preview['skipped'])->toBe([Reconcile::NOT_ACTIVE => 1])
        ->and($preview['waves'])->toBe([1, 1])
        ->and($preview['disruptive'])->toBeTrue()
        ->and($steps['release']['disruptive'])->toBeTrue()
        ->and($steps['release']['instances'])->toBe(2)
        ->and($steps['settings-job']['disruptive'])->toBeFalse();
});

// Going through them

it('updates a few first, then each batch once the one before has finished', function () {
    $instances = rolloutInstances(3);
    editPlan($instances->first()->plan, 5);
    Queue::fake();

    $rollout = Action::execute(new MassReconcile($instances->pluck('id')->all(), null, 1, 2));
    $rollout->status = TaskStatus::IN_PROGRESS;
    $rollout->save();
    $runner = app(TaskRunner::class);
    $finish = fn () => Task::where('action_slug', 'application_update')->get()->each->complete();

    $runner->execute($rollout->fresh());
    expect(startedUpdates())->toHaveCount(1);

    // Not before the wave has finished
    $runner->poll($rollout->fresh());
    expect(startedUpdates())->toHaveCount(1);

    $finish();
    $runner->poll($rollout->fresh());
    expect(startedUpdates())->toHaveCount(3);

    $runner->poll($rollout->fresh());
    expect(startedUpdates())->toHaveCount(3);

    $finish();
    $runner->poll($rollout->fresh());
    expect(startedUpdates())->toHaveCount(4)
        ->and($rollout->fresh()->status)->toBe(TaskStatus::IN_PROGRESS);

    $finish();
    $runner->poll($rollout->fresh());

    expect($rollout->fresh()->status)->toBe(TaskStatus::COMPLETE)
        ->and($rollout->fresh()->getValue('updated'))->toBe(4)
        // each instance once
        ->and(collect(startedUpdates())->pluck('instance')->unique()->count())->toBe(4);
});

it('stops when an update fails: the rest are not started', function () {
    $instances = rolloutInstances(3);
    editPlan($instances->first()->plan, 5);
    Queue::fake();

    $rollout = Action::execute(new MassReconcile($instances->pluck('id')->all(), null, 1, 2));
    $rollout->status = TaskStatus::IN_PROGRESS;
    $rollout->save();
    app(TaskRunner::class)->execute($rollout->fresh());

    $canary = Task::where('action_slug', 'application_update')->sole();
    $canary->status = TaskStatus::FAILED;
    $canary->save();

    app(TaskRunner::class)->poll($rollout->fresh());

    expect(Task::where('action_slug', 'application_update')->count())->toBe(1)
        ->and($rollout->fresh()->status)->toBe(TaskStatus::BLOCKED);
});

it('leaves out what is up to date by the time its wave comes', function () {
    $instances = rolloutInstances(1);
    editPlan($instances->first()->plan, 5);
    Queue::fake();

    $rollout = Action::execute(new MassReconcile($instances->pluck('id')->all(), null, 1, 5));
    $rollout->status = TaskStatus::IN_PROGRESS;
    $rollout->save();
    app(TaskRunner::class)->execute($rollout->fresh());

    // An admin updated the other one in the meantime
    app(UpdateDrift::class)->dismiss($instances->last()->fresh());
    Task::where('action_slug', 'application_update')->get()->each->complete();
    app(TaskRunner::class)->poll($rollout->fresh());

    expect(Task::where('action_slug', 'application_update')->count())->toBe(1)
        ->and($rollout->fresh()->status)->toBe(TaskStatus::COMPLETE)
        ->and($rollout->fresh()->getValue('skipped'))->toBe(1);
});

it('only starts when its window is open, and a window may cross midnight', function () {
    $instances = rolloutInstances(1);
    editPlan($instances->first()->plan, 5);
    Queue::fake();

    $this->travelTo(now()->setTime(12, 0));
    $rollout = Action::execute(new MassReconcile($instances->pluck('id')->all(), null, 1, 5, '22:00', '04:00'));
    $rollout->status = TaskStatus::IN_PROGRESS;
    $rollout->save();
    app(TaskRunner::class)->execute($rollout->fresh());

    expect(startedUpdates())->toBe([]);

    $this->travelTo(now()->setTime(23, 30));
    app(TaskRunner::class)->poll($rollout->fresh());

    expect(startedUpdates())->toHaveCount(1);
});

// What is updated on its own

it('is automatic where no step that brings it up to date is disruptive, once automatic updates are on', function () {
    $policy = app(UpdatePolicy::class);

    expect($policy->enabled())->toBeFalse()
        ->and($policy->defaultMode('subscription'))->toBe(UpdatePolicy::AUTO)
        ->and($policy->defaultMode('settings'))->toBe(UpdatePolicy::AUTO)
        ->and($policy->defaultMode('roles'))->toBe(UpdatePolicy::AUTO)
        ->and($policy->defaultMode('release'))->toBe(UpdatePolicy::MANUAL)
        ->and($policy->defaultMode('sso'))->toBe(UpdatePolicy::MANUAL)
        // nothing is known to bring it up to date
        ->and($policy->defaultMode('feature.anything'))->toBe(UpdatePolicy::MANUAL)
        // off to begin with
        ->and($policy->isAuto('settings'))->toBeFalse();

    $policy->enable(true);

    expect($policy->isAuto('settings'))->toBeTrue()
        ->and($policy->isAuto('release'))->toBeFalse();
});

it('keeps what an admin says about an aspect, and forgets it when it is what it would be anyway', function () {
    $policy = app(UpdatePolicy::class);
    $policy->enable(true);

    $policy->set('release', UpdatePolicy::AUTO);
    $policy->set('feature.addons', UpdatePolicy::AUTO);
    $policy->set('settings', UpdatePolicy::MANUAL);

    expect($policy->isAuto('release'))->toBeTrue()
        ->and($policy->isAuto('feature.addons'))->toBeTrue()
        ->and($policy->isAuto('settings'))->toBeFalse();

    $policy->set('release', UpdatePolicy::MANUAL);

    expect($policy->isAuto('release'))->toBeFalse()
        ->and(collect($policy->all())->firstWhere('key', 'release'))->toMatchArray(['mode' => 'manual', 'default' => 'manual', 'disruptive' => true]);
});

it('does nothing on its own while automatic updates are off', function () {
    $instance = rolloutInstances()->first();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    editPlan($instance->plan, 5);

    (new ScanInstancesForDrift([$instance->id]))->handle(app(UpdateDrift::class), app(AutoUpdates::class));

    expect(startedUpdates())->toBe([]);
});

it('updates what is automatic on its own, and never a disruptive step', function () {
    $instance = rolloutInstances()->first();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    app(UpdatePolicy::class)->enable(true);
    editPlan($instance->plan, 5);

    (new ScanInstancesForDrift([$instance->id]))->handle(app(UpdateDrift::class), app(AutoUpdates::class));

    // The release is out of date too, and left for an admin
    expect(startedUpdates())->toBe([['instance' => $instance->id, 'steps' => ['settings-job']]])
        ->and(UpdateState::drifted()->where('app_instance_id', $instance->id)->pluck('aspect')->all())->toContain('release')
        ->and($instance->fresh()->status)->toBe('updating');
});

it('leaves an app that is busy alone', function () {
    $instance = rolloutInstances()->first();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    app(UpdatePolicy::class)->enable(true);
    editPlan($instance->plan, 5);
    $instance->status = 'updating';
    $instance->save();

    (new ScanInstancesForDrift([$instance->id]))->handle(app(UpdateDrift::class), app(AutoUpdates::class));

    expect(startedUpdates())->toBe([]);
});

it('does not keep trying for ever when an update does not get it up to date', function () {
    $instance = rolloutInstances()->first();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    app(UpdatePolicy::class)->enable(true);
    editPlan($instance->plan, 5);
    $scan = fn () => (new ScanInstancesForDrift([$instance->id]))->handle(app(UpdateDrift::class), app(AutoUpdates::class));
    // The update fails: the task is over, the app is back, and what was out of date still is
    $fail = function () use ($instance) {
        Task::where('action_slug', 'application_update')->update(['status' => TaskStatus::FAILED->value]);
        AppInstance::whereKey($instance->id)->update(['status' => 'active']);
    };

    $scan();
    $fail();
    $state = fn () => UpdateState::where('app_instance_id', $instance->id)->where('aspect', 'settings')->first();

    expect(startedUpdates())->toHaveCount(1)
        ->and($state()->apply_attempts)->toBe(1);

    // Too soon
    $scan();
    expect(startedUpdates())->toHaveCount(1);

    $this->travel(AutoUpdates::RETRY_AFTER_HOURS + 1)->hours();
    $scan();
    $fail();
    expect(startedUpdates())->toHaveCount(2)
        ->and($state()->apply_attempts)->toBe(2);

    $this->travel(AutoUpdates::RETRY_AFTER_HOURS + 1)->hours();
    $scan();
    $fail();
    expect(startedUpdates())->toHaveCount(3);

    // Left for an admin
    $this->travel(AutoUpdates::RETRY_AFTER_HOURS + 1)->hours();
    $scan();
    expect(startedUpdates())->toHaveCount(AutoUpdates::MAX_ATTEMPTS);
});

it('forgets the tries once what was out of date has been brought up to date', function () {
    $instance = rolloutInstances()->first();
    // So that what the scan starts isn't run, and the scan is run here
    Queue::fake();
    app(UpdatePolicy::class)->enable(true);
    editPlan($instance->plan, 5);

    (new ScanInstancesForDrift([$instance->id]))->handle(app(UpdateDrift::class), app(AutoUpdates::class));
    app(UpdateDrift::class)->record($instance->fresh(), app(UpdateDrift::class)->current($instance->fresh(), ['settings']));

    $state = UpdateState::where('app_instance_id', $instance->id)->where('aspect', 'settings')->first();
    expect($state->apply_attempts)->toBe(0)
        ->and($state->apply_attempted_at)->toBeNull();
});

// Asking for it

it('previews and starts updating what an admin picked', function () {
    $instances = rolloutInstances(1);
    editPlan($instances->first()->plan, 5);
    $change = DefinitionChange::sole();
    Queue::fake();

    $this->actingAs(rolloutAdmin());

    $this->postJson('/admin/server/updates/preview', ['change' => (string) $change->id, 'canary' => 1, 'batch' => 5])
        ->assertOk()
        ->assertJsonPath('instances', 2)
        ->assertJsonPath('waves', [1, 1])
        ->assertJsonPath('disruptive', true);

    expect(Task::where('action_slug', 'mass_reconcile')->exists())->toBeFalse();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->post('/admin/server/updates/apply', ['change' => (string) $change->id, 'canary' => 1, 'batch' => 5]);

    $rollout = Task::where('action_slug', 'mass_reconcile')->sole();
    expect($rollout->getValue('instance_ids'))->toEqualCanonicalizing($instances->pluck('id')->all())
        ->and($rollout->getValue('canary'))->toBe(1)
        ->and($rollout->getValue('batch'))->toBe(5)
        // only what the edit put out of date
        ->and($rollout->getValue('aspects'))->toContain('settings');
});

it('updates only what was picked, not everything the instances are out of date for', function () {
    $instance = rolloutInstances()->first();
    editPlan($instance->plan, 5);
    Queue::fake();

    $this->followingRedirects();
    $this->from('/admin/server/updates')->actingAs(rolloutAdmin())
        ->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id], 'aspects' => ['settings']]);

    $rollout = Task::where('action_slug', 'mass_reconcile')->sole();
    app(TaskRunner::class)->execute($rollout);

    expect(startedUpdates())->toBe([['instance' => $instance->id, 'steps' => ['settings-job']]]);
});

it('says so when there is nothing to update for what was picked', function () {
    $instance = rolloutInstances()->first();
    editPlan($instance->plan, 5);
    AppInstance::whereKey($instance->id)->update(['status' => 'updating']);

    $this->from('/admin/server/updates')->actingAs(rolloutAdmin())
        ->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id]])
        ->assertSessionHas('error', __('admin.updates.nothing_to_update'));

    expect(Task::where('action_slug', 'mass_reconcile')->exists())->toBeFalse();
});

it('checks how a rollout is asked to go', function () {
    $instance = rolloutInstances()->first();
    editPlan($instance->plan, 5);

    $this->actingAs(rolloutAdmin());

    $this->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id], 'canary' => 99])->assertSessionHasErrors('canary');
    $this->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id], 'batch' => 0])->assertSessionHasErrors('batch');
    $this->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id], 'start_time' => '22:00'])->assertSessionHasErrors('end_time');
    $this->post('/admin/server/updates/apply', ['instance_ids' => [$instance->id], 'start_time' => 'late', 'end_time' => '04:00'])->assertSessionHasErrors('start_time');
    $this->post('/admin/server/updates/apply', [])->assertSessionHasErrors(['instance_ids', 'change']);
});

it('saves whether things are updated on their own, and which', function () {
    $this->actingAs(rolloutAdmin());

    $this->post('/admin/server/updates/policy', ['enabled' => true])->assertSessionHasNoErrors();
    expect(app(UpdatePolicy::class)->enabled())->toBeTrue();

    $this->post('/admin/server/updates/policy', ['aspect' => 'release', 'mode' => 'auto'])->assertSessionHasNoErrors();
    expect(app(UpdatePolicy::class)->isAuto('release'))->toBeTrue();

    $this->post('/admin/server/updates/policy', ['aspect' => 'release', 'mode' => 'sometimes'])->assertSessionHasErrors('mode');
    $this->post('/admin/server/updates/policy', ['enabled' => false])->assertSessionHasNoErrors();
    expect(app(UpdatePolicy::class)->enabled())->toBeFalse();
});

it('shows the admin whether things are updated on their own', function () {
    rolloutInstances();

    $this->actingAs(rolloutAdmin())
        ->get('/admin/server/updates')
        ->assertInertia(fn ($page) => $page
            ->where('policy.enabled', false)
            ->where('policy.aspects', function ($aspects) {
                $aspects = collect($aspects)->keyBy('key');

                return $aspects['release']['disruptive'] === true
                    && $aspects['release']['mode'] === 'manual'
                    && $aspects['settings']['mode'] === 'auto';
            }));
});

// The command line

it('says what it would update without updating it', function () {
    $instance = rolloutInstances()->first();
    editPlan($instance->plan, 5);
    Queue::fake();

    $this->artisan('updates:apply', ['--dry-run' => true])
        ->expectsOutputToContain('1 app instances')
        ->assertSuccessful();

    expect(Task::where('action_slug', 'mass_reconcile')->exists())->toBeFalse();
});

it('starts updating what is out of date from the command line, for the aspects asked for', function () {
    $instance = rolloutInstances()->first();
    editPlan($instance->plan, 5);
    Queue::fake();

    $this->artisan('updates:apply', ['--aspect' => ['settings'], '--canary' => 0, '--batch' => 3])->assertSuccessful();

    $rollout = Task::where('action_slug', 'mass_reconcile')->sole();
    expect($rollout->getValue('aspects'))->toBe(['settings'])
        ->and($rollout->getValue('canary'))->toBe(0)
        ->and($rollout->getValue('batch'))->toBe(3);
});

it('has nothing to say when everything is up to date', function () {
    rolloutInstances();

    $this->artisan('updates:apply')->expectsOutput('Nothing to update.')->assertSuccessful();

    expect(Task::where('action_slug', 'mass_reconcile')->exists())->toBeFalse();
});
