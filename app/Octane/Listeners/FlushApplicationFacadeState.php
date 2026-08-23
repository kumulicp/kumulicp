<?php

namespace App\Octane\Listeners;

use Illuminate\Support\Facades\Facade;

/**
 * Octane resets its own framework facades (Auth, Cache, DB, ...) individually
 * via the listeners in Laravel\Octane\Concerns\ProvidesDefaultConfigurationOptions,
 * but it has no listener that resets application-defined facades. A Facade
 * caches its resolved instance in a static property on the Facade base class,
 * separate from the container, so clearing the container's scoped bindings
 * (see FlushTemporaryContainerInstances) is not enough on its own: App\Support\
 * Facades\Organization (and Subscription, Application, Billing, Email) would
 * keep returning the object resolved for the worker's first request forever.
 *
 * This mirrors what Illuminate\Queue\QueueServiceProvider::registerWorker()
 * already does between queue jobs: forget scoped container instances, then
 * clear every facade's cached resolution.
 */
class FlushApplicationFacadeState
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        Facade::clearResolvedInstances();
    }
}
