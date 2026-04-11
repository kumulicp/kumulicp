<?php

namespace App\Providers;

use App\AppInstance;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Server;
use App\Services\DomainService;
use App\Support\Facades\Application;
use App\Support\Facades\Billing;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('domain', function ($app) {
            return new DomainService;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Gate::define('domain-direct-to-app-instance', function (User $user, OrgSubdomain $subdomain, AppInstance $app_instance) {
            $web_server = $app_instance->web_server->server->first();
            $find_subdomain = Application::instance($app_instance)->availableDomains()->where('id', $subdomain->id)->first();

            if ($subdomain->domain->type !== 'managed' && ! Domain::ipPointsToServer($subdomain, $web_server)) {
                return Response::deny(__('organization.domain.denied.ip', ['domain' => $subdomain->name, 'ip' => $app_instance->web_server->server->ip]));
            }

            return ($user->organization->status !== 'deactivated'
                && $find_subdomain
                && ! in_array($app_instance->status, ['deactivating', 'deactivated'])
                && $app_instance->belongsToOrgFamily($subdomain->organization)
                && $subdomain->domain->belongsToOrgFamily($app_instance->organization))
                ? Response::allow()
                : Response::deny(__('organization.app.denied.domain'));
        });

        Gate::define('domain-direct-to-web-server', function (User $user, OrgSubdomain $subdomain, Server $server) {
            return ($user->organization->status !== 'deactivated'
                && $subdomain->domain->belongsToOrganization($user->organization)
                && (Domain::isIntegratedRegistrar($subdomain->domain)
                    || Domain::ipPointsToServer($subdomain, $server)))
                ? Response::allow()
                : Response::deny('');
        });

        /* DOMAINS */
        Gate::define('view-domains', function (User $user) {
            $domains = $user->organization->domains()->where('type', '!=', 'base')->count();

            $organization_subscription_service = Subscription::all($user->organization);

            // Check if domains are enabled or organization has domains added from a previous subscription
            return ($organization_subscription_service->domainsEnabled() || $domains > 0)
                ? Response::allow()
                : Response::deny();
        });

        Gate::define('add-domains', function (User $user) {
            $organization_subscription_service = Subscription::all($user->organization);

            return $user->organization->status !== 'deactivated'
                && $organization_subscription_service->domainsEnabled()
                && ! $organization_subscription_service->base()->isDomainMax()
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.add'));
        });

        Gate::define('connect-domains', function (User $user) {
            $organization_subscription_service = Subscription::all($user->organization);

            return $user->organization->status !== 'deactivated'
                && $organization_subscription_service->domainsEnabled()
                && ! $organization_subscription_service->base()->isDomainMax()
                && $organization_subscription_service->base()->setting('domains.connect')
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.add'));
        });

        Gate::define('edit-domain', function (User $user, OrgDomain $domain) {
            $organization = $user->organization;

            return $domain->organization->status !== 'deactivated'
                && $domain->belongsToOrganization($organization)
                && (Subscription::domainsEnabled()
                    || $organization->domains()->where('type', '!=', 'managed')->count() > 0)
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.edit'));
        });

        Gate::define('register-domains', function (User $user) {
            $organization_subscription_service = Subscription::all($user->organization);

            if (! Billing::hasDefaultPaymentMethod()) {
                return Response::deny(__('organization.denied.payment_method_required'));
            }

            return $user->organization->status !== 'deactivated'
                && $organization_subscription_service->domainsEnabled()
                && $organization_subscription_service->base()->setting('domains.register')
                && ! $organization_subscription_service->base()->isDomainMax()
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.add'));
        });

        Gate::define('transfer-domains', function (User $user) {
            $organization_subscription_service = Subscription::all($user->organization);

            if (! Billing::hasDefaultPaymentMethod()) {
                return Response::deny(__('organization.denied.payment_method_required'));
            }

            return $user->organization->status !== 'deactivated'
                && $organization_subscription_service->domainsEnabled()
                && ! $organization_subscription_service->base()->isDomainMax()
                && $organization_subscription_service->base()->setting('domains.transfer')
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.add'));
        });

        Gate::define('redirect-domain', function (User $user, OrgSubdomain $subdomain) {
            return $user->organization->status !== 'deactivated'
                && $subdomain->domain->belongsToOrganization($user->organization)
                && is_null($subdomain->app_instance()->whereBelongsTo($subdomain, 'primary_domain')->first())
                ? Response::allow()
                : Response::deny(__('organization.domain.denied.redirect', ['domain' => $subdomain->name]));
        });

        Gate::define('transfer-in-domain', function (User $user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && Billing::hasDefaultPaymentMethod()
                && $domain->status === 'active'
                && $domain->type === 'connection'
                && $domain->belongsToOrganization($user->organization);
        });

        Gate::define('enable-email-domain', function (User $user, OrgDomain $domain) {
            $base_subscription = Subscription::base($user->organization);

            return $user->organization->status !== 'deactivated'
                && $domain->email_status === 'disabled'
                && $user->organization->status !== 'deactivated'
                && $base_subscription->emailEnabled()
                && $domain->status == 'active'
                && $domain->email_enabled == false
                && in_array($domain->type, ['managed', 'connection']);
        });

        Gate::define('disable-email-domain', function (User $user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && $domain->status === 'active'
                && $domain->email_enabled === true;
        });

        Gate::define('renew-domain', function (User $user, OrgDomain $domain) {
            if ($domain->tld) {
                $max_renew_years = $domain->tld->max_renew_years - 1;
            } else {
                $max_renew_years = 0;
            }

            return $user->organization->status !== 'deactivated'
                && Billing::hasDefaultPaymentMethod()
                && $domain->belongsToOrganization($user->organization)
                && $domain->type == 'managed'
                && $domain->status == 'active'
                && $domain->expiresIn() < $max_renew_years;
        });

        Gate::define('reactivate-domain', function (User $user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && Billing::hasDefaultPaymentMethod()
                && $domain->transfer_id > 0
                && $domain->type == 'managed'
                && $domain->status == 'deactivated'
                && $domain->belongsToOrganization($user->organization);
        });

        // This option only appears if a transfer failed and the organization decides to manage their domain themselves.
        Gate::define('self-manage-domain', function (User $user, OrgDomain $domain) {
            return $domain->transfer_id > 0
                && $domain->type === 'managed'
                && $domain->status === 'deactivated'
                && $domain->belongsToOrganization($user->organization);
        });

        Gate::define('request-domain-transfer', function (User $user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && $domain->belongsToOrganization($user->organization)
                && $domain->type === 'managed'
                && (new Carbon($domain->registered_at))->addDays(60) < now();
        });

        Gate::define('remove-domain', function (User $user, OrgDomain $domain) {
            return $domain->status !== 'removing'
                && ($domain->type == 'connection' || $domain->status == 'deactivated')
                && $domain->app_instance?->primary_domain_id !== $domain->id;
        });

        Gate::define('remove-subdomain', function (User $user, OrgSubdomain $subdomain) {
            return $user->organization->status !== 'deactivated'
                && $subdomain->domain->belongsToOrganization($user->organization)
                && $subdomain->app_instance?->primary_domain_id !== $subdomain->id;
        });

        Gate::define('set-app-domain', function (User $user, AppInstance $app_instance, OrgSubdomain $subdomain) {
            return $user->organization->status !== 'deactivated'
                && ! in_array($app_instance->status, ['activating', 'deactivating', 'deactivated'])
                && $subdomain->domain->belongsToOrganization($user->organization)
                && $app_instance->belongsToOrganization($user->organization)
                && (! $subdomain->app_instance || $subdomain->app_instance_id !== $app_instance->id);
        });

        Gate::define('add-tld', function (User $user) {
            return ! in_array(config('domains.default'), ['', null, 'default']);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['domain'];
    }
}
