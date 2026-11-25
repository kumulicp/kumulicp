<?php

use App\Actions\ActionResult;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpdateStep;
use App\AppInstance;
use App\Enums\TaskStatus;
use App\Jobs\Applications\AddLdapGroups;
use App\Organization;
use App\Services\Tasks\TaskRunner;
use App\Support\Facades\Action;
use App\Task;
use App\Updates\AspectRegistry;
use App\Updates\Aspects\Aspect;
use App\Updates\UpdateDrift;
use App\Updates\UpdateStep;
use App\Updates\UpdateSteps;
use App\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\Concerns\TestsApplicationLifecycle;
use Tests\Support\Concerns\TestsWithServerInterfaces;
use Tests\Support\TestSupports;

/**
 * An update runs the steps an admin chose, each as a task of its own. Upgrading
 * only changes the version and recommends the steps the new version may need.
 */
uses(TestsApplicationLifecycle::class, TestsWithServerInterfaces::class);

beforeEach(function () {
    $this->setupFakeServerInterfaces();
    $this->fakeNotificationsAndMail();
});

afterEach(function () {
    $this->restoreServerInterfaces();
});

// Records that it ran, and what it was given
class RecordingStep extends UpdateStep
{
    /** @var array<int, string> */
    public static array $ran = [];

    /** @param  array<int, string>  $names  The aspects it brings up to date */
    public function __construct(private string $key = 'recording', private array $after = [], private bool $applies = true, private array $names = []) {}

    public function aspects(): array
    {
        return $this->names;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Recording '.$this->key;
    }

    public function appliesTo(AppInstance $app_instance): bool
    {
        return $this->applies;
    }

    public function after(): array
    {
        return $this->after;
    }

    public function afterUpgrade(AppInstance $app_instance): bool
    {
        return true;
    }

    public function handle(Task $task, AppInstance $app_instance): ActionResult
    {
        self::$ran[] = $this->key;

        return ActionResult::done();
    }
}

// What a new version may need whatever the fingerprints say: its fingerprint never changes
class RecordingAspect extends Aspect
{
    public function key(): string
    {
        return 'recording';
    }

    public function label(): string
    {
        return 'Recording';
    }

    public function fingerprint(AppInstance $app_instance): string
    {
        return 'always the same';
    }
}

function updateStepsInstance(): AppInstance
{
    $support = new TestSupports;
    $support->seed();
    ['app' => $app, 'plan' => $plan] = $support->prepareDemoApp();

    return test()->runActivate($plan, Organization::find(1), $app);
}

/** Runs the update task, then every step task it started, in the order they were made */
function runUpdateWithSteps(AppInstance $instance, array $keys): Task
{
    Queue::fake();
    RecordingStep::$ran = [];

    $update = Action::execute(new ApplicationUpdate($instance, $keys));
    app(TaskRunner::class)->execute($update);
    $update->status = TaskStatus::IN_PROGRESS;
    $update->save();

    foreach (Task::where('action_slug', 'application_update_step')->orderBy('id')->get() as $step_task) {
        app(TaskRunner::class)->execute($step_task);
    }

    return $update->fresh();
}

it('has the steps every app has, and the ones it adds, in an order that has each after the steps it follows', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)
        ->register(new RecordingStep('second', after: ['first']))
        ->register(new RecordingStep('first'));

    $keys = app(UpdateSteps::class)->for($instance)->keys()->all();

    expect($keys)->toContain('release', 'settings-job', 'first', 'second')
        ->and(array_search('first', $keys))->toBeLessThan(array_search('second', $keys));
});

it('leaves out a step that does not apply to the app, and does not wait for one that is not there', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)
        ->register(new RecordingStep('not-here', applies: false))
        ->register(new RecordingStep('follows-a-missing-one', after: ['not-here']));

    $keys = app(UpdateSteps::class)->for($instance)->keys()->all();

    expect($keys)->not->toContain('not-here')->and($keys)->toContain('follows-a-missing-one');
});

it('offers the steps that go with an update ticked, and the others not', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)->register(new RecordingStep);

    $options = collect(app(UpdateSteps::class)->options($instance))->keyBy('key');

    expect($options['release']['checked'])->toBeTrue()
        ->and($options['settings-job']['checked'])->toBeTrue()
        ->and($options['recording']['checked'])->toBeFalse();
});

it('runs only the steps asked for, each as a task of its own, and completes when they have', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)->register(new RecordingStep('first'))->register(new RecordingStep('second', after: ['first']));

    $update = runUpdateWithSteps($instance, ['first', 'second']);

    $tasks = Task::where('action_slug', 'application_update_step')->orderBy('id')->get();
    expect($tasks->map(fn (Task $task) => $task->getValue('step'))->all())->toBe(['first', 'second'])
        ->and(RecordingStep::$ran)->toBe(['first', 'second'])
        // second follows first: it does not start until first is complete
        ->and($tasks[1]->dependencies()->pluck('tasks.id')->all())->toBe([$tasks[0]->id])
        // the update waits for both
        ->and($update->dependencies()->wherePivot('gate', Task::GATE_FINISH)->pluck('tasks.id')->sort()->values()->all())->toBe($tasks->pluck('id')->sort()->values()->all());

    // The last step finishing is what wakes the update: it completes without waiting for a scheduled pass
    expect($update->fresh()->status)->toBe(TaskStatus::COMPLETE)
        ->and($instance->fresh()->status)->toBe('active');
});

it('does not run a step it was not asked for', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)->register(new RecordingStep('wanted'))->register(new RecordingStep('unwanted'));

    runUpdateWithSteps($instance, ['wanted']);

    expect(RecordingStep::$ran)->toBe(['wanted'])
        ->and(Task::where('action_slug', 'application_update_step')->count())->toBe(1);
});

it('still does what an update always did when no steps are asked for', function () {
    $instance = updateStepsInstance();

    Queue::fake();
    $update = Action::execute(new ApplicationUpdate($instance));
    app(TaskRunner::class)->execute($update);

    expect(Task::where('action_slug', 'application_update_step')->exists())->toBeFalse();
});

it('fails the step task, rather than the whole run, when its step is no longer there', function () {
    $instance = updateStepsInstance();
    Queue::fake();
    $task = Action::execute(new ApplicationUpdateStep($instance, 'gone'));

    app(TaskRunner::class)->execute($task);

    expect($task->fresh()->status)->toBe(TaskStatus::FAILED)
        ->and($task->fresh()->error_code)->toBe('unknown_step');
});

it('flags the steps a new version may need once an app is upgraded, ticks them, and clears them when they have run', function () {
    $instance = updateStepsInstance();
    app(AspectRegistry::class)->register(new RecordingAspect);
    app(UpdateSteps::class)->register(new RecordingStep(names: ['recording']));
    Bus::fake([AddLdapGroups::class]);
    app(UpdateDrift::class)->baseline($instance->fresh());

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->not->toContain('recording');

    $this->runUpgrade($instance);

    // Though nothing its fingerprint covers changed
    expect(app(UpdateDrift::class)->drift($instance->fresh()))->toContain('recording');
    $option = collect(app(UpdateSteps::class)->options($instance->fresh()))->keyBy('key')['recording'];
    expect($option['checked'])->toBeTrue()
        ->and($option['out_of_date'])->toBeTrue();

    runUpdateWithSteps($instance->fresh(), ['recording']);

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->not->toContain('recording');
});

it('flags them for an app that nothing was stored for before it was upgraded', function () {
    $instance = updateStepsInstance();
    app(AspectRegistry::class)->register(new RecordingAspect);
    app(UpdateSteps::class)->register(new RecordingStep(names: ['recording']));
    Bus::fake([AddLdapGroups::class]);

    $this->runUpgrade($instance);

    expect(app(UpdateDrift::class)->drift($instance->fresh()))->toContain('recording');
});

it('does not create LDAP groups or set up single sign-on when an app is upgraded', function () {
    $instance = updateStepsInstance();
    $customizations = Task::where('action_slug', 'process_customizations')->count();
    Bus::fake([AddLdapGroups::class]);

    $this->runUpgrade($instance);

    Bus::assertNotDispatched(AddLdapGroups::class);
    expect(Task::where('action_slug', 'application_sso_setup')->exists())->toBeFalse()
        ->and(Task::where('action_slug', 'process_customizations')->count())->toBe($customizations);
});

// The admin page

it('lists the update steps on the app page', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)->register(new RecordingStep);
    config(['toggle.flags.shared-apps' => true]);

    $this->actingAs(User::find(1))
        ->get("/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Organizations/Apps/AppEdit')
            ->where('update_steps', fn ($steps) => collect($steps)->pluck('key')->contains('recording')
                && collect($steps)->firstWhere('key', 'release')['checked'] === true));
})->skip(fn () => ! Route::has('organizations.app.edit'), 'edit route name differs');

it('starts an update with the steps that were ticked', function () {
    $instance = updateStepsInstance();
    app(UpdateSteps::class)->register(new RecordingStep);
    Queue::fake();

    $this->actingAs(User::find(1))
        ->post("/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/update", ['steps' => ['recording']])
        ->assertRedirect()
        ->assertSessionHas('success');

    $task = Task::where('action_slug', 'application_update')->latest('id')->first();
    expect($task->getValue('steps'))->toBe(['recording']);
});

it('refuses an update with no steps, or with one the app does not have', function () {
    $instance = updateStepsInstance();
    $url = "/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/update";

    $this->actingAs(User::find(1))->post($url, ['steps' => []])->assertSessionHasErrors('steps');
    $this->actingAs(User::find(1))->post($url, ['steps' => ['nonsense']])->assertSessionHasErrors('steps.0');

    expect(Task::where('action_slug', 'application_update')->exists())->toBeFalse();
});

it('does not update from a GET any more', function () {
    $instance = updateStepsInstance();

    $this->actingAs(User::find(1))
        ->get("/admin/organizations/{$instance->organization_id}/apps/{$instance->id}/update")
        ->assertStatus(405);
});
