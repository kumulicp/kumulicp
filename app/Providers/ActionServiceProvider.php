<?php

namespace App\Providers;

use App\Services\AccountManagerService;
use App\Services\ActionService;
use App\Services\ApplicationService;
use App\Services\BackupService;
use App\Services\FastCacheService;
use App\Services\OrganizationService;
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

        $this->app->singleton('applications', function ($app) {
            return new ApplicationService($app);
        });

        $this->app->singleton('server_interfaces', function ($app) {
            return new ServerInterfaceService($app);
        });

        $this->app->singleton('backups', function ($app) {
            return new BackupService;
        });

        $this->app->singleton('subscription', function ($app) {
            return new SubscriptionService;
        });

        $this->app->singleton('organizations', function ($app) {
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
        return ['actions', 'applications', 'server_interfaces', 'backups', 'subscription', 'users', 'organizations', 'account_manager', 'fastcache', 'settings'];
    }
}
