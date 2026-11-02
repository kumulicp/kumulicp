<?php

namespace App\Services\Tickets\Integrations;

use App\Contracts\Tickets\TicketIntegrationDriver;
use InvalidArgumentException;

/**
 * The drivers an installation offers.
 *
 * Bound as a real singleton and only ever holds class names, for the same
 * reason as BillingDriverRegistry: under Octane a module's boot() runs once per
 * worker, so what it registers must survive for the worker's life. Drivers
 * themselves are made fresh for each use and keep no state.
 *
 * A module adds a driver from its service provider's boot():
 *
 *     app(TicketIntegrationRegistry::class)->register(MyTrackerDriver::class);
 */
class TicketIntegrationRegistry
{
    /** @var array<string, class-string<TicketIntegrationDriver>> */
    private array $drivers = [];

    /**
     * @param  class-string<TicketIntegrationDriver>  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, TicketIntegrationDriver::class)) {
            throw new InvalidArgumentException("{$class} must implement ".TicketIntegrationDriver::class);
        }

        $this->drivers[$class::key()] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    public function driver(string $key): ?TicketIntegrationDriver
    {
        return isset($this->drivers[$key]) ? app($this->drivers[$key]) : null;
    }

    /**
     * @return array<string, class-string<TicketIntegrationDriver>>
     */
    public function all(): array
    {
        return $this->drivers;
    }
}
