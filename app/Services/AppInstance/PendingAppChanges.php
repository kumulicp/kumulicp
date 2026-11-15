<?php

namespace App\Services\AppInstance;

use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\Actions\Apps\ProcessCustomizations;
use App\AppInstance;
use App\Events\Users\UserStorageUpdated;
use App\Jobs\Applications\UpdateLDAPGroups;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Work for an app that has to wait until the app is active again.
 *
 * A plan can always be changed, but what the change does to the app itself (the chart upgrade and update,
 * its customizations, LDAP groups, per-user storage) needs an app that answers. For an app that is
 * `unavailable` or `updating` that work is written down on the instance instead, and done once, when the
 * app next saves as `active` (see AppInstance::booted()), or by the sweep in ApplyPendingAppChanges.
 *
 * Recorded under the instance's `pending_changes` setting:
 *  - ldap: update the app's LDAP groups
 *  - storage: process every user's storage options in the app
 *  - plan_change: the chart upgrade, update and customizations of a plan change, with the customizations
 */
class PendingAppChanges
{
    public const SETTING = 'pending_changes';

    // The statuses an app comes back from. Others are left to run as they always did: an app that is
    // activating has nothing to wait for, and one being removed never becomes active again.
    public const DEFERRING_STATUSES = [AppInstance::STATUS_UNAVAILABLE, 'updating'];

    public static function waiting(AppInstance $app): bool
    {
        return in_array($app->status, self::DEFERRING_STATUSES, true);
    }

    public static function hasPending(AppInstance $app): bool
    {
        return ! empty(Arr::get($app->settings ?? [], self::SETTING));
    }

    /**
     * Writes the changes down if the app can't take them now. Returns whether it did: false means the app is
     * active (or has none to wait for) and the caller does the work itself.
     *
     * The status is read again under a row lock, so an app that became active a moment ago isn't left with
     * work nobody will pick up.
     *
     * @param  array{ldap?: bool, storage?: bool, plan_change?: array{customizations: array}}  $changes
     */
    public function defer(AppInstance $app, array $changes): bool
    {
        // Most calls are for an app that is fine: no query for those
        if (! self::waiting($app)) {
            return false;
        }

        return DB::transaction(function () use ($app, $changes) {
            $locked = AppInstance::whereKey($app->getKey())->lockForUpdate()->first();

            if (! $locked || ! self::waiting($locked)) {
                return false;
            }

            $settings = $locked->settings ?? [];
            $pending = array_merge(Arr::get($settings, self::SETTING, []), $changes);
            Arr::set($settings, self::SETTING, array_merge($pending, ['since' => Arr::get($pending, 'since', now()->toIso8601String())]));

            $locked->settings = $settings;
            $locked->save();

            $app->forceFill(['settings' => $locked->settings])->syncOriginalAttributes(['settings']);

            return true;
        });
    }

    /**
     * Does what was written down, once, if the app is active. Returns whether anything was done.
     */
    public function applyIfDue(AppInstance $app): bool
    {
        if (! self::hasPending($app)) {
            return false;
        }

        $pending = DB::transaction(function () use ($app) {
            $locked = AppInstance::whereKey($app->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'active' || ! self::hasPending($locked)) {
                return null;
            }

            // Taken and cleared in one go, so two workers can't both run it
            $pending = Arr::get($locked->settings, self::SETTING);
            $settings = $locked->settings;
            unset($settings[self::SETTING]);
            $locked->settings = $settings;
            $locked->save();

            return $pending;
        });

        if ($pending === null) {
            return false;
        }

        $app = $app->fresh();
        Log::info("App instance {$app->name} is active again, applying the changes that waited for it", ['changes' => array_keys($pending)]);

        if (Arr::get($pending, 'ldap')) {
            UpdateLDAPGroups::dispatch($app);
        }

        if (Arr::get($pending, 'storage')) {
            UserStorageUpdated::dispatch($app->organization, app_instance: $app);
        }

        if ($plan_change = Arr::get($pending, 'plan_change')) {
            $this->queuePlanChange($app, Arr::get($plan_change, 'customizations', []));
        }

        return true;
    }

    /**
     * The tasks that put a plan change into the app. With no parent they start by themselves, the customizations
     * once the update is done; with the plan update's task as the parent they follow it, like they always have.
     */
    public function queuePlanChange(AppInstance $app, array $customizations, ?Task $parent = null): void
    {
        $application = Application::instance($app);

        if (Arr::get(Application::get($app->application->slug), 'activation_type', 'chart') === 'chart') {
            Action::execute(new ApplicationUpgrade($application->get(), $application->get()->version), $parent);
        }

        $update = Action::execute(new ApplicationUpdate($application->get()), $parent);

        Action::execute(new ProcessCustomizations($application->get(), $customizations), $parent ?? $update, delay: true);
    }
}
