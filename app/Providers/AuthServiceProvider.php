<?php

namespace App\Providers;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Plan;
use App\Services\Organization\JointSubscriptionService;
use App\Services\SubscriptionService;
use App\Support\AccountManager\UserManager;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Application as AppFacade;
use App\Support\Facades\Billing;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        /* Admin */
        Gate::define('admin', function (User $user, ?UserManager $other_user = null) {
            $user = $other_user ?? AccountManager::users()->find($user->username);

            return $user->permissions()->hasControlPanelAdminAccess()
                ? Response::allow()
                : Response::deny(__('admin.denied'));
        });

        /* Organization */

        Gate::define('login', function (User $user) {
            return $user->is_allowed;
        });

        Gate::define('active', function (User $user) {
            $organization = $user->organization;

            return ($organization->status !== 'deactivated' && $organization->plan)
                ? Response::allow()
                : Response::deny(__('organization.denied.subscription'));
        });

        Gate::define('view-organization', function (User $user, ?\App\Organization $organization = null) {
            return $organization && ($organization->is($user->organization) || ($organization->parent_organization && $organization->parent_organization->is($user->organization)))
                ? Response::allow()
                : Response::deny(__('organization.denied.exists'));
        });

        Gate::define('edit-organization', function (User $user, ?\App\Organization $organization) {
            return $organization && ($organization->is($user->organization) || ($organization->parent_organization && $organization->parent_organization->is($user->organization)))
                ? Response::allow()
                : Response::deny(__('organization.denied.exists'));
        });

        Gate::define('delete-organization', function (User $user, \App\Organization $organization) {
            return $organization->status === 'deactivated';
        });

        Gate::define('view-suborganizations', function (User $user) {
            return ! $user->organization->parent_organization
                && $user->organization->plan->setting('suborganizations.enabled') ?? false
                ? Response::allow()
                : Response::deny();
        });

        Gate::define('add-suborganization', function (User $user) {
            return ! $user->organization->parent_organization
                ? Response::allow()
                : Response::deny();
        });

        /* Subscription */
        Gate::define('select-plan', function (User $user, \App\Organization $organization, Plan $plan) {
            $plans = (new SubscriptionService($organization))->all()->dryBaseChange($plan);
            $base_plan = $plans->base();

            // Checks plan to confirm that organization isn't going to be breaking any limits before changing
            if ($plan->org_type != $organization->type || $plan->archive) {
                return Response::deny(__('organization.plan.denied.unavailable'));
            } elseif ($base_plan && $base_plan->isAnyMaxBroken()) {
                return Response::deny(__('organization.plan.denied.limit'));
            } elseif ($organization->plan_id == $plan->id) {
                return Response::deny(__('organization.plan.denied.subscribed'));
            } else {
                return Response::allow();
            }
        });

        Gate::define('unsubscribe', function (User $user, \App\Organization $organization) {
            return ($user->organization->id === $organization->id
                || $user->organization->id === $organization->parent_organization?->id)
                && $organization->status === 'active';
        });

        Gate::define('resubscribe', function (User $user, \App\Organization $organization) {
            return ($user->organization->id === $organization->id
                || $user->organization->id === $organization->parent_organization?->id)
                && $organization->status === 'deactivating';
        });

        Gate::define('has-billing-account', function (User $user) {
            $organization = $user->organization;

            return (Billing::isBillable())
                ? Response::allow()
                : Response::deny(__('organization.plan.denied.billing'));
        });

        /* APPS */
        Gate::define('select-app-plan', function (User $user, AppPlan $plan) {
            $base_plan = Subscription::base();
            $app_plans = Arr::get($base_plan->app_plans, $plan->application->slug.'.plans');

            return is_array($app_plans)
                && in_array($plan->id, $app_plans)
                && ! $plan->archive
                && $base_plan->appEnabled($plan->application)
                ? Response::allow()
                : Response::deny(__('organization.plan.unavailable'));
        });

        Gate::define('update-to-plan', function (User $user, AppInstance $app_instance, AppPlan $plan) {
            $base_plan = Subscription::base();
            $app_plan = Subscription::appInstanceSubscription($app_instance, $plan);

            return $user->organization->status != 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && ! $app_plan->archive && $base_plan->appPlanEnabled($plan)
                && ! $app_plan->isAnyMaxBroken()
                ? Response::allow()
                : Response::deny(__('organization.plan.unavailable'));
        });

        Gate::define('add-additional-storage', function (User $user) {
            $organization = $user->organization;
            $base_plan = Subscription::base($organization);

            return $organization->status != 'deactivated' && $organization->plan && ! $base_plan->isMax('storage');
        });

        Gate::define('add-additional-app-storage', function (User $user, UserManager $user_manager, AppInstance $app_instance) {
            $app_plan = Subscription::app_instance($app_instance);

            return $user->organization->status !== 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && $app_instance->plan && $app_plan->additionalStorageEnabled();
        });

        Gate::define('view-app', function (User $user, Application $app) {
            $organization = $user->organization;
            $base_plan = Subscription::base($organization);

            return $base_plan->appEnabled($app);
        });

        /* APPS */
        Gate::define('activate-app', function (User $user, Application $app) {
            $organization = $user->organization;
            $subscription = $organization->children ? new JointSubscriptionService($organization) : Subscription::access();
            $base_plan = Subscription::base();

            $plans_with_higher_app_limit = $subscription->isAppLimitReached($app);

            // If app limit hasn't been reached and there is no parent app or if there is a parent app, that it has been installed
            if ($base_plan->isMaxApps($app) && $plans_with_higher_app_limit) {
                return Response::deny(__('organization.app.denied.plan_limit', ['max' => $base_plan->appMax($app)]), 'plan_limit_reached');
            } elseif ($base_plan->isMaxApps($app) && ! $plans_with_higher_app_limit) {
                return Response::deny(__('organization.app.denied.limit'), 'limit_reached');
            } elseif ($app->parent_app && AppFacade::availableParents($app)->count() == 0) {
                return Response::deny(__('organization.app.denied.no_parent_app', ['app' => $app->name, 'parent_app' => $app->parent_app->name]), 'missing_parent_app');
            } elseif (in_array($app->domain_option, ['subdomains', 'primary']) && count($organization->main_domains()) === 0) {
                return Response::deny(__('organization.app.denied.primary_domain'));
            }

            return $organization->status !== 'deactivated' && $app->versions()->where('status', 'active')->first() && $base_plan->appEnabled($app)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.activation', ['app' => $app->name]));
        });

        Gate::define('customize-app', function (User $user, AppInstance $app_instance) {
            $customizations = AppFacade::instance($app_instance)->customizations();

            // Check if app is installed before allowing user to customize it
            return ($user->organization->status !== 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && $app_instance->status === 'active'
                && count($customizations) > 0)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.activated'));
        });

        Gate::define('edit-app', function (User $user, AppInstance $app_instance) {
            return $user->organization->status !== 'deactivated'
                && ! in_array($app_instance->status, ['activating', 'deactivating', 'deactivated'])
                && $app_instance->belongsToOrganization($user->organization)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.inactive'));
        });

        Gate::define('change-app-plan', function (User $user, AppInstance $app_instance) {
            $plan = Subscription::app_instance($app_instance);

            return $user->organization->status === 'active'
                && $app_instance->status === 'active'
                && count($plan->allAvailable()) > 1
                && $app_instance->belongsToOrganization($user->organization)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.deactivation', ['app' => $app_instance->label]));
        });

        Gate::define('deactivate-app', function (User $user, AppInstance $app_instance) {
            return $user->organization->status === 'active'
                && $app_instance->status === 'active'
                && $app_instance->belongsToOrganization($user->organization)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.deactivation', ['app' => $app_instance->label]));
        });

        Gate::define('reactivate-app', function (User $user, AppInstance $app_instance) {
            return $user->organization->status === 'active'
                && $app_instance->status === 'deactivating'
                && $app_instance->belongsToOrganization($user->organization)
                ? Response::allow()
                : Response::deny(__('organization.app.denied.reactivation', ['app' => $app_instance->label]));
        });

        /* USERS */
        Gate::define('view-user', function (User $user, ?UserManager $select_user = null) {
            $select_user_org = $select_user?->organization();

            return $select_user &&
                ($select_user->organization()?->id === $user->organization->id
                || $select_user->organization()?->parent_organization_id === $user->organization->id)
                ? Response::allow()
                : Response::deny(__('organization.user.denied.exists'));
        });

        Gate::define('add-user', function (User $user) {
            $organization = $user->organization;
            $maxed = Subscription::base($organization)->isMax('standard');

            return $organization->status !== 'deactivated' && ! $maxed
                ? Response::allow()
                : Response::deny(__('organization.user.denied.add'));
        });

        Gate::define('edit-user', function (User $user, ?UserManager $user_manager = null) {
            if (! $user_manager) {
                return Response::deny(__('organization.user.denied.exists'));
            }

            return ($user->organization->status != 'deactivated')
                ? Response::allow()
                : Response::deny(__('organization.user.denied.edit', ['name' => $user_manager?->attribute('name')]));
        });

        Gate::define('delete-user', function (User $user, ?UserManager $user_manager = null) {
            if (! $user_manager) {
                return Response::deny(__('organization.user.denied.exists'));
            }

            if ($user->username === $user_manager->attribute('username')) {
                return Response::deny(__('organization.user.denied.delete_self'));
            }

            return (! $user_manager->permissions()->hasControlPanelAdminAccess())
                ? Response::allow()
                : Response::deny(__('organization.user.denied.delete_admin', ['name' => $user_manager?->attribute('name')]));
        });

        Gate::define('add-app-user', function (User $user, AppInstance $app_instance) {
            $organization = $user->organization;

            if ($plan = $app_instance->plan) {
                $plan = Subscription::app_instance($app_instance);
            }

            return $organization->status !== 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && $plan && ! $plan->isMax('standard');
        });

        Gate::define('update-standard-user', function (User $user, UserManager $user_manager) {
            $plan = Subscription::base();

            return $user_manager
                && $user->organization->status !== 'deactivated'
                && $plan
                && (! $plan->isMax('standard') || $user_manager->userAccessType() === 'standard');
        });

        Gate::define('update-basic-user', function (User $user, UserManager $user_manager) {
            $plan = Subscription::base();

            return $user_manager
                && $user->organization->status !== 'deactivated'
                && $plan
                && (! $plan->isMax('basic') || $user_manager->userAccessType() === 'basic');
        });

        Gate::define('update-app-standard-user', function (User $user, UserManager $user_manager, AppInstance $app_instance) {
            if ($plan = $app_instance->plan) {
                $plan = Subscription::app_instance($app_instance);
            }

            return $user_manager
                && $user->organization->status !== 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && $plan
                && (! $plan->isMax('standard')
                    || ($user_manager->appUserAccessType($app_instance) === 'standard')
                );
        });

        Gate::define('update-app-basic-user', function (User $user, UserManager $user_manager, AppInstance $app_instance) {
            if ($plan = $app_instance->plan) {
                $plan = Subscription::app_instance($app_instance); // TODO Need to remove refresh, without it, there are problems with Pest testing
            }

            return $user_manager
                && $user->organization->status !== 'deactivated'
                && $app_instance->belongsToOrganization($user->organization)
                && $plan
                && $plan && $plan->isBasicUsersEnabled()
                && (! $plan->isMax('basic')
                    || $user_manager->appUserAccessType($app_instance) == 'basic'
                );
        });
    }
}
