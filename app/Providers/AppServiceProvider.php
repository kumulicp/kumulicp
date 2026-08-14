<?php

namespace App\Providers;

use App\Organization;
use App\Services\MenuService;
use App\Sso\OidcProvider;
use App\Support\Facades\Application;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use SocialiteProviders\Authentik\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('menu', function ($app) {
            return new MenuService;
        });

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (config('app.proxy') == 'true') {
            URL::forceScheme('https');
        }

        Paginator::useBootstrap();
        Cashier::useCustomerModel(Organization::class);
        // Cashier::calculateTaxes();
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('oidc', OidcProvider::class);
        });

        // The 'applications' singleton (ApplicationService) memoizes AppInstance
        // wrappers by ID for the life of the process. queue:work reuses one process
        // across many jobs, so without this the cache can serve a stale AppInstance
        // (wrong version_id) to a job that ran hours after the one that populated it.
        Queue::before(function () {
            Application::flushInstances();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['menu'];
    }
}
