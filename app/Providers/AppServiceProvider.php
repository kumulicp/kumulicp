<?php

namespace App\Providers;

use App\Organization;
use App\Services\AppProfileRegistry;
use App\Services\BillingDriverRegistry;
use App\Services\MenuService;
use App\Sso\OidcProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(AppProfileRegistry::class);
        $this->app->singleton(BillingDriverRegistry::class);

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

        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute((int) config('api.rate_limit'))
                ->by($user ? $user::class.':'.$user->getAuthIdentifier() : (string) $request->ip());
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
