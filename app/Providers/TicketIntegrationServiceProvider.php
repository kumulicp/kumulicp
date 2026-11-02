<?php

namespace App\Providers;

use App\Services\Tickets\Integrations\Drivers\GenericWebhookDriver;
use App\Services\Tickets\Integrations\Drivers\GitHubDriver;
use App\Services\Tickets\Integrations\Drivers\GitLabDriver;
use App\Services\Tickets\Integrations\TicketIntegrationRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the integration types that come with the control panel. Modules add
 * their own the same way from their own providers (see TicketIntegrationRegistry).
 */
class TicketIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A real singleton so what modules register survives for the life of an Octane worker
        $this->app->singleton(TicketIntegrationRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(TicketIntegrationRegistry::class);

        $registry->register(GenericWebhookDriver::class);
        $registry->register(GitHubDriver::class);
        $registry->register(GitLabDriver::class);
    }
}
