<?php

namespace App\Services;

use App\Integrations\Applications\AppProfile;

/**
 * Holds module-contributed AppProfiles across the whole worker lifetime.
 *
 * ApplicationService is bound scoped() (see ActionServiceProvider) so it gets
 * a fresh instance every request under Octane/FrankenPHP. Module service
 * providers only run boot() once, at worker startup, so a module calling
 * Application::register() from boot() (e.g. ERPNextAppServiceProvider) would
 * only register its profile on the single scoped instance alive at that
 * moment -- gone as soon as Octane discards that instance at request end.
 *
 * This registry is bound as a real singleton (never flushed) so it survives
 * for the worker's life. ApplicationService::register() writes through to it,
 * and ApplicationService::__construct() re-hydrates from it, so every fresh
 * scoped instance picks up whatever modules registered back at boot time.
 */
class AppProfileRegistry
{
    /** @var array<string, AppProfile> */
    private array $profiles = [];

    public function register(AppProfile $profile): void
    {
        $this->profiles[$profile->name()] = $profile;
    }

    /** @return array<string, AppProfile> */
    public function all(): array
    {
        return $this->profiles;
    }
}
