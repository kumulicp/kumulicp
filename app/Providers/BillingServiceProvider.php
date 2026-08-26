<?php

namespace App\Providers;

use App\Services\BillingService;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Scoped: BillingService memoizes a gateway driver instance built
        // against whichever organization was current when it was first
        // resolved. A singleton would keep serving that same organization's
        // billing gateway for the life of a FrankenPHP/Octane worker.
        $this->app->scoped('billing', function ($app) {
            return new BillingService;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['billing'];
    }
}
