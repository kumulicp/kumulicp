<?php

namespace App\Providers;

use App\AppInstance;
use App\Monitoring\AppInstanceObserver;
use App\Organization;
use App\Services\AppProfileRegistry;
use App\Services\BillingDriverRegistry;
use App\Services\MenuService;
use App\Sso\OidcProvider;
use App\Support\DevIngress;
use App\Updates\AspectRegistry;
use App\Updates\Aspects\ReleaseAspect;
use App\Updates\Aspects\RolesAspect;
use App\Updates\Aspects\SettingsAspect;
use App\Updates\Aspects\SsoAspect;
use App\Updates\Detection\DefinitionObserver;
use App\Updates\Detection\ImpactResolver;
use App\Updates\Steps\LdapGroupsStep;
use App\Updates\Steps\ReleaseStep;
use App\Updates\Steps\SettingsJobStep;
use App\Updates\Steps\SsoStep;
use App\Updates\UpdateDrift;
use App\Updates\UpdateSteps;
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
        $this->app->singleton(UpdateSteps::class, fn () => (new UpdateSteps)
            ->register(new ReleaseStep)
            ->register(new SettingsJobStep)
            ->register(new LdapGroupsStep)
            ->register(new SsoStep));
        $this->app->singleton(AspectRegistry::class, fn () => (new AspectRegistry)
            ->register(new ReleaseAspect)
            ->register(new SettingsAspect)
            ->register(new RolesAspect)
            ->register(new SsoAspect));
        $this->app->singleton(UpdateDrift::class);

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

        AppInstance::observe(AppInstanceObserver::class);

        // The uptime monitor makes its own requests, so in local dev it needs the
        // same route to the cluster's ingress that API calls get. A handler set
        // by config (the tests') is left alone.
        if (config('uptime-monitor.uptime_check.guzzle_options.handler') === null && $handler = DevIngress::guzzleHandler()) {
            config(['uptime-monitor.uptime_check.guzzle_options.handler' => $handler]);
        }

        // Edits to what app instances depend on are looked at for what they put out of date
        foreach (app(ImpactResolver::class)->watched() as $definition) {
            $definition::observe(DefinitionObserver::class);
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
