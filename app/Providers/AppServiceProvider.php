<?php

namespace App\Providers;

use App\Organization;
use App\Services\MenuService;
use App\Sso\OidcProvider;
use App\Support\Facades\Application;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
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
        if (env('PROXY') == 'true') {
            URL::forceScheme('https');
        }

        App::setLocale((auth()->user())?->organization->default_locale ?? env('APP_LOCALE'));

        Paginator::useBootstrap();
        Cashier::useCustomerModel(Organization::class);
        // Cashier::calculateTaxes();
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('oidc', OidcProvider::class);
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
