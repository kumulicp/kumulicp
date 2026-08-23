<?php

namespace App\Providers;

use App\Services\AccountManagerService;
use App\Services\ActionService;
use App\Services\ApplicationService;
use App\Services\BackupService;
use App\Services\FastCacheService;
use App\Services\OrganizationService;
use App\Services\SecurityToolService;
use App\Services\ServerInterfaceService;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use App\Support\Facades\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class ActionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('actions', function ($app) {
            return new ActionService;
        });

        // Scoped, not singleton: ApplicationService memoizes AppInstance lookups
        // (and its activate() calls) that are only valid for the organization
        // handling the current request/job. A plain singleton would let that
        // cache leak into the next request under FrankenPHP/Octane worker mode.
        $this->app->scoped('applications', function ($app) {
            return new ApplicationService;
        });

        $this->app->singleton('server_interfaces', function ($app) {
            return new ServerInterfaceService;
        });

        $this->app->singleton('security_tools', function ($app) {
            return new SecurityToolService;
        });

        $this->app->singleton('backups', function ($app) {
            return new BackupService;
        });

        // Scoped: SubscriptionService resolves the current organization's plan
        // at construction and memoizes it in $this->plans for the object's
        // lifetime, so it must not survive past the request/job that built it.
        $this->app->scoped('subscription', function ($app) {
            return new SubscriptionService;
        });

        // Scoped: OrganizationService caches the authenticated user's
        // organization (from Auth::user()) once in its constructor. Every
        // controller resolves the "current organization" through this
        // binding, so a plain singleton would leak org A's context into
        // org B's request on the same worker.
        $this->app->scoped('organizations', function ($app) {
            return new OrganizationService;
        });

        $this->app->singleton('account_manager', function ($app) {
            return new AccountManagerService;
        });

        $this->app->singleton('settings', function ($app) {
            return new SettingsService;
        });

        $this->app->singleton('fastcache', function ($app) {
            return new FastCacheService;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {}

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['actions', 'applications', 'server_interfaces', 'security_tools', 'backups', 'subscription', 'users', 'organizations', 'account_manager', 'fastcache', 'settings'];
    }
}
