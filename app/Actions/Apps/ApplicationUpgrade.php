<?php

namespace App\Actions\Apps;

use App\Actions\ActionResult;
use App\Actions\NativeAction;
use App\Actions\Prerequisites;
use App\AppInstance;
use App\AppVersion;
use App\Events\AppInstanceSubscriptionChanged;
use App\Notifications\ApplicationUpgraded;
use App\Services\AppInstance\AppStorageService;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Task;
use App\Updates\UpdateSteps;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ApplicationUpgrade extends NativeAction
{
    public $slug = 'application_upgrade';

    public static bool $long_running = true;

    public $background = false;

    public function __construct(AppInstance $app_instance, AppVersion $version, ?Prerequisites $prerequisites = null, bool $notify = true)
    {
        $this->organization = $app_instance->organization;
        $this->version = $version;
        if ($prerequisites) {
            $this->setCustomValues($prerequisites);
        }
        $this->addCustomValue(['notify' => $notify]);

        $this->description = __('actions.upgrading_app', ['app' => $version->application->name]);
        $this->app_instance = Application::instance($app_instance);
    }

    // Once the task exists
    public function onCreate(Task $task)
    {
        self::begin($task);
    }

    /**
     * What making the task did to the app, which retrying it does again.
     */
    private static function begin(Task $task): void
    {
        $app_instance = Application::instance($task->app_instance);

        // Where cancelling goes back to; not overwritten when retried
        if (! $task->getValue('previous_version_id')) {
            $task->custom_values = array_merge($task->customValues(), ['previous_version_id' => $app_instance->version_id]);
            $task->save();
        }

        $app_instance->version_id = $task->version_id;
        $app_instance->status = 'updating';
        $app_instance->save();

        // An app that is a job on a shared hub is upgraded by a job; the task
        // isn't done until that is
        if (Application::profile($app_instance->application->slug)->activationType($app_instance->get()) == 'job' && $job = ActionFacade::execute(new ApplicationUpdateJob($app_instance->get(), 'upgrade'), null, true)) {
            $task->dependOn($job, Task::GATE_FINISH);
        }
    }

    public static function handle(Task $task): ActionResult
    {
        $app_instance = Application::instance($task->app_instance);

        // A shared child without multisite has no release of its own to
        // upgrade -- the hub's own upgrade covers it entirely.
        if (! $app_instance->usesOwnResources()) {
            return ActionResult::wait();
        }

        if (Application::profile($app_instance->application->slug)->activationType($app_instance->get()) == 'job' && $parent_app = Application::instance($app_instance->parent)) {
            $parent_app->connect('web')->update();
        } else {
            $server = $app_instance->connect('web')->update();
        }

        // The rest of what a new version may need (groups in LDAP, single sign-on,
        // the features' actions) is left to an update: it is recommended there
        // once the upgrade is done

        AppInstanceSubscriptionChanged::dispatch($task->app_instance);

        // Check if upgrade required
        $app_storage_service = new AppStorageService($task->app_instance);
        $calculated_storage = $app_storage_service->calculateTotalAppStorage();
        $app_storage_quota = $app_instance->setting('storage_quota');

        if ($calculated_storage > $app_storage_quota) {
            // Setting expand_storage to true triggers protocol to expand storage in kubernetes
            $app_instance->updateSetting('expand_storage', true);
            $app_instance->updateSetting('storage_quota', $calculated_storage);
        }

        return ActionResult::wait();
    }

    /**
     * The app goes back to active; if the upgrade hadn't started it goes back
     * to the version it was on too.
     */
    public static function cancelled(Task $task): ?string
    {
        $app_instance = $task->app_instance;

        if (! $app_instance || $app_instance->status !== 'updating') {
            return null;
        }

        $started = $task->hasStarted();

        if (! $started && $previous = $task->getValue('previous_version_id')) {
            $app_instance->version_id = $previous;
        }

        $app_instance->status = 'active';
        $app_instance->save();

        return $started ? __('messages.action.cancelled_upgrade_started') : null;
    }

    public static function reset(Task $task): void
    {
        // What it was made with, and the version the app was on before the first
        // try, which cancelling goes back to; not what the last run left
        $task->custom_values = Arr::only($task->customValues(), ['notify', 'previous_version_id']);

        self::begin($task);
    }

    public static function poll(Task $task): ActionResult
    {
        $app_instance = Application::instance($task->app_instance);

        // A shared child without multisite has nothing of its own to wait
        // on -- the hub's own upgrade task tracks completion for it.
        if (! $app_instance->usesOwnResources()) {
            $app_instance->version_id = $task->version_id;
            $app_instance->status = 'active';
            $app_instance->save();

            return ActionResult::done();
        }

        // A multisite shared child (e.g. ERPNext) has no web release of its
        // own to poll -- same hub redirect as run() above.
        $server = (Application::profile($app_instance->application->slug)->activationType($app_instance->get()) == 'job' && $parent_app = $app_instance->parent)
            ? Application::instance($parent_app)->connect('web')
            : $app_instance->connect('web');

        if ($app_instance->setting('expand_storage')) {
            if ($task->updated_at < now()->subMinutes(5)) {
                $app_instance->updateSetting('expand_storage', false);

                // Run another update to restore app
                $server->update();
            }

            return ActionResult::wait();
        }

        $status = $server->checkStatus();

        if ($status['active']) {
            // Set app status to active
            $app_instance->version_id = $task->version_id;
            $app_instance->status = 'active';
            $app_instance->save();

            app(UpdateSteps::class)->recommendAfterUpgrade($app_instance->get());

            return ActionResult::done()->then(function (Task $task) use ($app_instance) {
                if ($task->getValue('notify') && in_array($app_instance->version->announcement_location, ['local', 'remote'])) {
                    $task->organization->notifyAdmins(new ApplicationUpgraded($task));
                    $task->groupNotified();
                }
            });
        }

        if ($status['pending'] ?? false) {
            if (method_exists($server, 'recoverStuckRelease')
                && (! method_exists($server, 'canRecoverStuckRelease') || $server->canRecoverStuckRelease())
                && static::recoverStuckPendingRelease($task, $server)) {
                // The next run starts the upgrade fresh
                return ActionResult::retryLater(__('messages.action.release_reset'), 0);
            }
        } else {
            static::forgetPendingSince($task);
        }

        // Not an actual failure -- surfaces the live chart status (e.g.
        // "myapp: pending-upgrade") on the task while polling for
        // completion continues, so it's visible instead of the task
        // just looking stuck with no explanation.
        $task->error_message = $status['message'];
        $task->save();

        return ActionResult::wait();
    }

    /**
     * If the release has sat pending (helm status pending-install/-upgrade/
     * -rollback) longer than the long queue's worker timeout, the process
     * running `helm upgrade --wait` was almost certainly killed mid-operation
     * (e.g. the container restarted) rather than the operation still
     * genuinely running -- helm refuses any further action on a release
     * stuck like that, so it can never recover on its own. Deletes the
     * stuck release secret so the next attempt (the caller retries the task)
     * runs `helm upgrade --install` fresh.
     *
     * @return bool true if recovery was triggered (caller should stop)
     */
    protected static function recoverStuckPendingRelease(Task $task, $server): bool
    {
        $pending_since = $task->getValue('helm_pending_since');

        if (! $pending_since) {
            static::rememberPendingSince($task);

            return false;
        }

        $threshold = config('queue.connections.database-long.retry_after', 960);

        if (now()->diffInSeconds(Carbon::parse($pending_since), absolute: true) < $threshold) {
            return false;
        }

        $server->recoverStuckRelease();
        static::forgetPendingSince($task);

        return true;
    }

    protected static function rememberPendingSince(Task $task): void
    {
        $custom_values = $task->custom_values ?? [];
        $custom_values['helm_pending_since'] = now()->toISOString();
        $task->custom_values = $custom_values;
        $task->save();
    }

    protected static function forgetPendingSince(Task $task): void
    {
        if (! array_key_exists('helm_pending_since', $task->custom_values ?? [])) {
            return;
        }

        $custom_values = $task->custom_values;
        unset($custom_values['helm_pending_since']);
        $task->custom_values = $custom_values;
        $task->save();
    }
}
