<?php

namespace App\Contracts\Tickets;

use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\TestResult;

/**
 * A kind of outside system tickets can be sent to. Drivers are registered with
 * TicketIntegrationRegistry; a module adds its own the same way the built-in
 * ones are added. Implement TracksIssues (issue trackers) or ReceivesEvents
 * (webhooks) as well to say what the driver does.
 *
 * Drivers hold no state: a new instance is made for every use, so nothing leaks
 * between requests or organizations under Octane.
 */
interface TicketIntegrationDriver
{
    /** Stable identifier stored with each integration, e.g. "github". */
    public static function key(): string;

    public function label(): string;

    /**
     * The settings this driver needs. The admin form is built from this, so a
     * new driver needs no front-end work. Each field is an array with:
     *   key, type (text|url|secret|select|number|toggle), label, and optionally
     *   help, required, default, options (for select), pattern (regex).
     * Fields of type "secret" are encrypted, never sent back to the browser,
     * and only replaced when a new value is submitted.
     *
     * @return list<array<string, mixed>>
     */
    public function configSchema(): array;

    /**
     * What the driver can do: values from Capability.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Check the settings work, without changing anything outside.
     */
    public function testConnection(IntegrationConfig $config): TestResult;
}
