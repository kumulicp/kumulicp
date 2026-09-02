<?php

namespace App\Services;

/**
 * Holds module-contributed billing driver classes across the whole worker
 * lifetime.
 *
 * BillingService is bound scoped() (see BillingServiceProvider) so its
 * memoized per-organization gateway instance ($organization, $current_driver)
 * never leaks between requests/organizations under Octane -- but that means a
 * module's one-time, boot()-time BillingService::register() call would only
 * land on the single scoped instance alive at that moment, then vanish once
 * Octane discards it at request end (same bug class as AppProfileRegistry /
 * ApplicationService).
 *
 * This registry is a real singleton (never flushed) so it survives for the
 * worker's life. BillingService::register() writes through to it, and its
 * constructor rehydrates from it, so every fresh scoped instance still knows
 * about drivers a module registered back at boot time.
 */
class BillingDriverRegistry
{
    /** @var array<string, class-string> */
    private array $drivers = [];

    public function register(string $driver, string $class): void
    {
        $this->drivers[$driver] = $class;
    }

    /** @return array<string, class-string> */
    public function all(): array
    {
        return $this->drivers;
    }
}
