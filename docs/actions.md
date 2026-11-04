# Writing an action

An *action* is a unit of background work (activate an app, update DNS, run a
job on a server). `Action::execute(new MyAction(...))` saves a `Task` row, and
from then on the task runner drives it: it queues the task, runs it on the
queue, polls it until it has finished, and records what happened.

## The lifecycle

```
pending ──► ready ──► queued ──► in_progress ──► complete
   ▲                                  │  └─────► failed
   └────────── (retry later) ─────────┘
```

* `Action::execute()` saves the task and, if its prerequisites pass, queues it
  straight away. Otherwise the scheduled `task-dispatcher` (every minute) makes
  it `ready` once they do, and queues it.
* A queue worker runs `RunAction`, which **claims** the task (only one run can
  hold it) and calls your `handle()`.
* While the task is `in_progress` and nothing is running it, the dispatcher
  calls `poll()` each minute.
* The dispatcher also puts a task back on the queue if its job was lost, and
  fails one that is past its `$deadline_minutes`.

Every step is written to `task_events` (`$task->events`).

## A native action

Extend `NativeAction` and override what you need. A task that finishes in
`handle()` needs nothing else.

```php
class ResizeDisk extends NativeAction
{
    public $slug = 'resize_disk';

    public function __construct(AppInstance $app_instance)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->description = 'Resize disk';
    }

    public static function handle(Task $task): ActionResult
    {
        $job = Disk::resize($task->app_instance);

        return ActionResult::wait(['job_id' => $job->id]);   // keep polling
    }

    public static function poll(Task $task): ActionResult
    {
        return Disk::done($task->getValue('job_id'))
            ? ActionResult::done()
            : ActionResult::wait();
    }

    public static function compensate(Task $task): void
    {
        // undo what handle() did; called when the task fails
    }
}
```

Register it in `ActionService::$actions` under a group and slug.

### Results

| Return | Effect |
| --- | --- |
| `ActionResult::done($values)` | task is `complete` |
| `ActionResult::wait($values)` | stays `in_progress`; `poll()` is called each minute |
| `ActionResult::retryLater($reason, $seconds)` | back to `pending`, not run again before `$seconds` |
| `ActionResult::fail($reason, $code)` | `compensate()`, then `failed` |

`$values` are merged into `$task->custom_values`. `->then(fn (Task $task) => ...)`
runs once the new state is saved.

### Exceptions

Throw these from `handle()` or `poll()` instead of catching and setting a status:

* `WaitingException` - something isn't ready yet (not an error); retried after
  `$retry_after` seconds (default 60).
* `RetryableException` - a transient failure such as `ConnectionFailedException`;
  retried with `Action::$backoff` (60, 120, 300, 600 seconds, the last repeating).
* `PermanentFailure` - cannot succeed; `compensate()` and `failed`.

Any other exception fails the task (and the queue job) after `compensate()`,
unless the action sets `public static int $max_attempts` above 1, which makes
the runner retry it with the same backoff first. Only do that for an action that
is safe to run twice.

### Settings

* `$deadline_minutes` - fail (and `compensate()`) a task still open after this long.
* `$long_running` - run on the `long` queue.
* `$background` - hidden from the customer-facing task list.

## Dependencies

A task can wait for other tasks, at one of two points:

* **Before it starts** - `$action->dependsOn($task)` (in the constructor, or before
  `Action::execute()`). The task stays pending until every one is complete.
* **Before it completes** - `$action->waitFor($task, ...)` on the action `run()`
  returns (or `ActionResult::wait()->waitingFor($task, ...)` from a native one), for
  work the action started and is waiting on. The task stays in progress and isn't
  polled until every one is complete. It replaces whatever the task waited on before;
  an action that doesn't call `waitFor()` leaves it as it is.

`Action::execute($action, $parent, delay: true)` makes the new task wait for `$parent`.

```php
$job = ActionFacade::execute(new ApplicationUpdateJob($app, 'update_settings'), $task, true);

return (new self($app))->waitFor($job);
```

A dependency that has **failed** doesn't leave its dependents waiting for ever: they
become `blocked` (with `blocked_from` saying whether they were waiting to start or
already running). Retry the failed task and they carry on by themselves; delete it and
they are released. Deleting a task, finished or not, also releases whatever waited on
it - `ApplicationDelete` relies on that to say it is done. A task that completes starts
the tasks waiting for it straight away rather than on the next scheduled pass.

`task_group` still groups related tasks for the UI and notifications, but no longer
makes them start each other.

## Constructors

A constructor stores what the action was asked to do (ids, flags) and may check
it is sensible - throwing from it is how a controller finds out the request is
invalid. It must not save anything, dispatch a job, execute another action or fire
an event: the constructor also runs when something builds the action without
meaning to do the work, and the work would then be done twice.

Work that has to happen once the task exists goes in `onCreate(Task $task)`. It runs
when the task is created - not for a duplicate that `Action::execute()` turns away -
and again when the task is retried, so it must be safe to repeat.

```php
public function __construct(AppInstance $app_instance)
{
    $this->app_instance = $app_instance;          // just remember it
}

public function onCreate(Task $task)
{
    $this->app_instance->status = 'deleting';     // now the task exists
    $this->app_instance->save();
}
```

`handle()` and `poll()` get everything from the `Task` (its custom values and
relations); don't rebuild the action with `new self(...)` in them.

`onCreate` is also where an action makes what its task is about: `ApplicationActivate`
creates its app instance there (saving the id on the task straight away, so a retry
carries on with that instance instead of making another).

(Older actions use `postGenerate()` for this. It does the same, but also counts
the creation as a first attempt on the task, which is why new code uses `onCreate`.)

## Steps

An action that is a fixed sequence of stages, each of which may take a while, is a
`StepAction`: list the steps and write each as a `Step`.

```php
class MoveDisk extends StepAction
{
    public static function steps(): array
    {
        return [Snapshot::class, Copy::class, Switch::class];
    }
}

class Copy extends Step
{
    public function run(Task $task): ActionResult        // start it
    {
        $copy = Disks::copy($task->getValue('snapshot_id'));

        return ActionResult::wait(['copy_id' => $copy->id]);
    }

    public function check(Task $task): ActionResult      // each poll while it runs
    {
        return Disks::copied($task->getValue('copy_id')) ? ActionResult::done() : ActionResult::wait();
    }

    public function rollback(Task $task): ?string        // undo it; returns a note for the error message
    {
        Disks::remove($task->getValue('copy_id'));

        return null;
    }

    public function timeoutMinutes(): ?int
    {
        return 120;
    }
}
```

* A step that returns `done()` goes straight on to the next in the same pass; one
  that returns `wait()` is `check()`ed on each poll until it is done.
* If a step fails, times out, or throws something unexpected, the steps that were
  started are rolled back, last first, and the task fails. A step that is merely
  waiting on something (`WaitingException`, `RetryableException`) starts again
  later instead.
* Only one pass runs over a task's steps at a time, and progress is kept in
  `task_steps` (`$task->steps`), so the task resumes where it was after a restart.
  Anything a step needs to remember goes in the task's custom values (return it
  in the result).
* Override `failureMessage()` on the action to say what the failure reads like once
  the rollback is done.

Most actions don't need steps: one that starts something and polls for it is just
`handle()` and `poll()`.

## Cancelling

An admin can cancel a task that hasn't finished (not one a worker is running at that
moment). The task stops and is marked `cancelled`; its action's `compensate()` runs
(for a `StepAction`, the started steps are rolled back, last first), and then
`cancelled(Task $task): ?string`, for undoing what *creating* the task changed - an
app marked "updating" or "deleting", say. Return a note if something couldn't be
undone; it goes in the task's history.

```php
public static function cancelled(Task $task): ?string
{
    if ($task->attempts > 0) {
        return 'Already started: the app was left as it is.';
    }

    $task->app_instance->update(['status' => 'active']);

    return null;
}
```

Whatever waited for a cancelled task becomes `blocked`, as it does for a failed one,
until the task is retried or deleted.

## Operating tasks

The admin Tasks page lists tasks; a task's own page (`/admin/server/tasks/{id}`) shows its
history (`task_events`), steps, what it waits for and what waits for it, and its values
(secrets masked).

| Status | Retry | Cancel | Delete |
| --- | --- | --- | --- |
| pending, ready, queued, in progress | | yes | |
| blocked | yes | yes | |
| failed | yes | | yes |
| cancelled | yes | | yes |
| complete | | | yes |

A task can't be cancelled while a worker is running it (try again in a moment). Deleting
a task releases whatever waited for it, so cancel one that is still going first.
Retry, cancel and delete are POST/DELETE requests and are recorded with who made them.

A task that has stopped getting anywhere logs one error for each reason - which reaches
the Logs page, the dashboard, email and Matrix - and is not reported again for the same
reason. The limits are in `config/tasks.php`:

* **blocked** - blocked for an hour;
* **stalled** - in progress, not being run, with no change for six hours (and no deadline
  of its own);
* **waiting** - never started after a day;
* **requeued** - queued but never started three times: the queue workers are probably down.

## Older actions

Actions written before `TaskAction` have static `run()`, `complete()`, `retry()`
and `revert()` methods that change the task themselves. They keep working
unchanged (see `LegacyHandler`); port one by extending `NativeAction` and
returning `ActionResult`s.
