<?php

namespace App\Providers;

use App\OrgDomain;
use App\Services\EmailService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('email', function ($app) {
            return new EmailService;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Gate::define('view-emails', function (User $user) {
            $organization = $user->organization;
            $plan = Subscription::all($organization);
            $domains = $organization->domains()->active()->emailEnabled()->emailActive()->count();

            return $organization->status !== 'deactivated'
                && (Organization::countEmail() > 0
                    || ($plan->domainsEnabled() && $plan->emailEnabled() && $domains > 0))
                ? Response::allow()
                : Response::deny(__('organization.email.denied.view'));
        });

        Gate::define('add-email-accounts', function (User $user) {
            $organization = $user->organization;
            $base_plan = Subscription::refresh()->base($organization);

            return $organization->status !== 'deactivated'
                && $base_plan->emailEnabled()
                && ! $base_plan->isMax('email')
                ? Response::allow()
                : Response::deny(__('organization.email.denied.create'));
        });

        Gate::define('add-user-email-account', function (User $user, $organization_user) {
            return $user->organization->status != 'deactivated'
                && Subscription::base($user->organization)->emailEnabled()
                && AccountManager::users()->find($organization_user)->isUserAccessType('standard')
                ? Response::allow()
                : Response::deny();
        });

        Gate::define('add-user-email-account-to-domain', function (User $user, $organization_user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && $domain->organization_id == $user->organization->id
                && $domain->belongsToOrganization($user->organization)
                && Subscription::base($user->organization)->emailEnabled()
                && AccountManager::users()->find($organization_user)->isUserAccessType('standard')
                ? Response::allow()
                : Response::deny(__('organization.user.denied.email_domain'));
        });

        Gate::define('activate-emails', function (User $user) {
            return $user->organization->status !== 'deactivated'
                && Subscription::emailEnabled()
                && $user->organization->primary_domain
                && $user->organization->primary_domain->type != 'base'
                && ! $user->organization->primary_domain->email_server()
                ? Response::allow()
                : Response::deny(__('organization.email.denied.activate'));
        });

        Gate::define('edit-email-settings', function (User $user) {
            $organization = $user->organization;
            $domains = $organization->domains()->emailEnabled()->active()->get();

            return $user->organization->status !== 'deactivated'
                && Subscription::emailEnabled()
                && $domains->count() != 0
                ? Response::allow()
                : Response::deny(__('organization.email.denied.edit'));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['email'];
    }
}
