<?php

namespace App\Support;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\ApplicationSource;
use App\Enums\TaskStatus;
use App\VersionTestRun;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * What every page of a shared app needs to draw the shared app layout: its
 * tabs, and, while it is still to be set up, the activation steps that sit on
 * top of all of them. The pages that double up as a shared app's (its
 * instance's admin pages, its plan's pages, its test runs) are not told about
 * it by their controllers; it is found from the route, see HandleInertiaRequests.
 */
class SharedAppContext
{
    /**
     * Added, and waiting to be set up and activated through Kumuli CP.
     */
    public const SETUP = 'setup';

    /**
     * Installed and run outside Kumuli CP. Its users come through the central LDAP
     * groups, so all it has is the domain organizations click through to.
     */
    public const EXTERNAL = 'external';

    /**
     * Deployed (or being deployed) by Kumuli CP.
     */
    public const ACTIVE = 'active';

    /**
     * The shared app the page being asked for belongs to, if it is one.
     *
     * @return array<string, mixed>|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        $shared_app = $route->parameter('shared_app');
        $app = $route->parameter('app');
        $plan = $route->parameter('plan');
        $run = $route->parameter('run');

        $instance = match (true) {
            $shared_app instanceof AppInstance => $shared_app,
            $app instanceof AppInstance => $app,
            $plan instanceof AppPlan => $plan->hidden ? $plan->instance : null,
            $run instanceof VersionTestRun => $run->app_plan_id ? AppInstance::where('plan_id', $run->app_plan_id)->first() : null,
            default => null,
        };

        return $instance?->isSharedApp() ? self::payload($instance) : null;
    }

    /**
     * Whether kumulicp can deploy the app: only apps with a registered profile.
     */
    public static function isDeployable(Application $app): bool
    {
        return $app->source === ApplicationSource::Profile;
    }

    /**
     * Whether the shared app was added but never deployed, and can be.
     */
    public static function canBeActivated(AppInstance $shared_app): bool
    {
        return self::isDeployable($shared_app->application)
            && ! $shared_app->isManagedExternally()
            && $shared_app->isAwaitingActivation();
    }

    public static function state(AppInstance $shared_app): string
    {
        return match (true) {
            // An app that can't be deployed is always run outside Kumuli CP, whether or not it was marked so
            $shared_app->isManagedExternally() || ! self::isDeployable($shared_app->application) => self::EXTERNAL,
            self::canBeActivated($shared_app) => self::SETUP,
            default => self::ACTIVE,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(AppInstance $shared_app): array
    {
        $state = self::state($shared_app);
        $external = $state === self::EXTERNAL;
        $instance = "/admin/organizations/{$shared_app->organization_id}/apps/{$shared_app->id}";
        $plan = self::planUrl($shared_app);
        // Features, configurations and secrets are only there for an app with a registered profile
        $plan_extras = ! $external && $shared_app->plan?->hasFullSettings();

        return [
            'id' => $shared_app->id,
            'label' => $shared_app->label,
            'application' => $shared_app->application->name,
            'status' => $shared_app->status,
            'state' => $state,
            // Where each tab leads, or null while the page means nothing yet: the app
            // instance's pages wait for it to be deployed, an externally managed app
            // has only its domain to change, and has no plan to set up.
            'urls' => [
                'view' => "/admin/service/shared-apps/{$shared_app->id}",
                'edit' => $state === self::ACTIVE || $external ? "{$instance}/edit" : null,
                // The chart is reviewed while setting it up, too
                'helm_values' => $state === self::ACTIVE || $state === self::SETUP ? "{$instance}/helm-values" : null,
                'plan' => $external ? null : $plan,
                'plan_edit' => $external ? null : "{$plan}/edit",
                'plan_features' => $plan_extras ? "{$plan}/features" : null,
                'plan_configurations' => $plan_extras ? "{$plan}/configurations" : null,
                'plan_secrets' => $plan_extras ? "{$plan}/secrets" : null,
            ],
            'setup' => $state === self::SETUP ? self::setup($shared_app) : null,
            'update_required' => $state === self::ACTIVE && self::updateRequired($shared_app),
        ];
    }

    /**
     * Whether the plan's settings were changed after the app instance was last
     * deployed or updated. Saving them only changes the plan: the running app
     * takes them when its instance is updated.
     */
    public static function updateRequired(AppInstance $shared_app): bool
    {
        // While it is updating, the update is the one that takes them
        if ($shared_app->status !== 'active' || ! $shared_app->plan) {
            return false;
        }

        $applied = $shared_app->tasks()
            ->whereIn('action_slug', ['application_activate', 'application_update'])
            ->where('status', TaskStatus::COMPLETE->value)
            ->max('updated_at');

        return $applied !== null && $shared_app->plan->updated_at?->gt(Carbon::parse($applied)) === true;
    }

    public static function planUrl(AppInstance $shared_app): string
    {
        return "/admin/apps/{$shared_app->application->slug}/plans/{$shared_app->plan_id}";
    }

    public static function runUrl(AppInstance $shared_app, VersionTestRun $run): string
    {
        return "/admin/apps/{$shared_app->application->slug}/versions/{$shared_app->version->name}/tests/{$run->id}";
    }

    /**
     * The steps to activate a shared app: its version, its servers and
     * configuration on its plan, a look at the chart they produce, the tests on
     * that plan, and then the activation itself.
     *
     * @return array<string, mixed>
     */
    private static function setup(AppInstance $shared_app): array
    {
        $version = $shared_app->version;
        $plan = $shared_app->plan;
        $run = $version->planTestRuns($plan)->first();
        $servers_saved = $plan->web_server_id !== null;
        $can_activate = $version->canBeActivatedOn($plan);
        $plan_url = self::planUrl($shared_app);

        return [
            'version' => ['id' => $version->id, 'name' => $version->name],
            'versions' => AppVersion::where('application_id', $shared_app->application_id)
                ->get()
                ->sort(fn (AppVersion $a, AppVersion $b) => version_compare($b->name, $a->name))
                ->map(fn (AppVersion $available) => ['id' => $available->id, 'name' => $available->name])
                ->values(),
            'servers' => [
                'web' => $plan->web_server?->name,
                'database' => $plan->database_server?->name,
                'sso' => $plan->sso_server?->name,
            ],
            'servers_saved' => $servers_saved,
            'gate' => $version->testGate($plan),
            'can_activate' => $can_activate,
            'run_url' => $run ? self::runUrl($shared_app, $run) : null,
            'bypassed_by' => $run?->bypassed_by?->name,
            'bypass_reason' => $run?->bypass_reason,
            // The first step still to do, counting the version as the first
            'current_step' => match (true) {
                ! $servers_saved => 1,
                ! $can_activate => 4,
                default => 5,
            },
            'links' => [
                'plan' => "{$plan_url}/edit",
                'configurations' => "{$plan_url}/configurations",
                'helm_values' => "/admin/organizations/{$shared_app->organization_id}/apps/{$shared_app->id}/helm-values",
            ],
        ];
    }
}
