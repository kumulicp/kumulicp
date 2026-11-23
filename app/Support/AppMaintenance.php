<?php

namespace App\Support;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Support\Facades\Action;
use App\Support\Facades\Application;

/**
 * Putting an app in, and taking it out of, maintenance mode as an admin, from
 * its admin edit page. The app's own release is left as it is except for its
 * ingress, which is off while in maintenance mode, and a second release serves
 * a maintenance page at the app's domain instead (see MaintenanceChart).
 * Both are applied by rolling the app's release out again.
 */
class AppMaintenance
{
    /**
     * Whether the app is deployed in a way that can have a maintenance page: as
     * releases of its own, on a driver that installs them with Helm. A site
     * inside another app's release is served by that app's ingress, and the
     * ingress of an app that has sites inside its release serves all of them:
     * neither is the app's alone to turn off.
     */
    public static function supported(AppInstance $app): bool
    {
        if ($app->parent_id || $app->isSharedApp() || ! $app->usesOwnResources() || $app->children()->exists()) {
            return false;
        }

        if (Application::profile($app->application->slug)->activationType($app) === 'job') {
            return false;
        }

        return in_array($app->web_server?->server?->interface, ['kubernetes', 'rancher'], true);
    }

    /** Why the app can't be moved to the state asked for right now, or null */
    public static function blocker(AppInstance $app, bool $enable): ?string
    {
        if (! self::supported($app)) {
            return __('admin.organizations.apps.denied.maintenance_unsupported');
        }

        // An app that is being updated, migrated or deleted is already being moved by a task
        if ($app->status !== 'active') {
            return __('admin.organizations.apps.denied.maintenance_not_active');
        }

        if ($app->inMaintenance() === $enable) {
            return __($enable ? 'admin.organizations.apps.denied.maintenance_already_on' : 'admin.organizations.apps.denied.maintenance_already_off');
        }

        return null;
    }

    /** Starts the roll out that puts the app in, or takes it out of, maintenance mode */
    public static function apply(AppInstance $app, bool $enable): void
    {
        $app->updateSetting('maintenance.enabled', $enable);

        try {
            Action::execute(new ApplicationUpdate($app, ['release']));
        } catch (\Throwable $e) {
            // Nothing is rolling out, so the app stays as it was
            $app->updateSetting('maintenance.enabled', ! $enable);
            $app->status = 'active';
            $app->save();

            throw $e;
        }
    }
}
