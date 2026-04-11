<?php

namespace App\Providers;

use App\Services\BillingService;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('billing', function ($app) {
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
