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

## Older actions

Actions written before `TaskAction` have static `run()`, `complete()`, `retry()`
and `revert()` methods that change the task themselves. They keep working
unchanged (see `LegacyHandler`); port one by extending `NativeAction` and
returning `ActionResult`s.
