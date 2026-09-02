<?php

namespace App\Octane\Listeners;

/**
 * nwidart/laravel-modules caches scanned Module objects in a private static
 * property on FileRepository (self::$modules), not on the container. Adding
 * its RepositoryInterface/ActivatorInterface/ModuleManifest singletons to
 * octane.flush (see FlushTemporaryContainerInstances) forces a fresh
 * LaravelFileRepository to be built each request, but that new instance's
 * scan() still short-circuits on the static cache and hands back the exact
 * same Module objects from the worker's first request -- each one still
 * holding that first request's container. Calling fireEvent() on one of
 * those stale Module objects (e.g. via module:enable/disable) then fails to
 * resolve even core bindings like 'events'.
 *
 * resetModules() is nwidart's own public API for clearing that static cache
 * (see Nwidart\Modules\Traits\CanClearModulesCache), so this mirrors
 * FlushApplicationFacadeState's approach for the same class of bug.
 */
class FlushModuleRepositoryState
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        app('modules')->resetModules();
    }
}
