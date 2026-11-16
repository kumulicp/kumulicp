<?php

namespace App\Services\Tickets\Integrations;

/**
 * The settings of one integration, as a driver sees them.
 */
final class IntegrationConfig
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values, private bool $allowInternal = false) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /**
     * Whether requests may go to private network addresses (a self-hosted
     * tracker inside the cluster). Off unless an administrator chose it.
     */
    public function allowInternal(): bool
    {
        return $this->allowInternal;
    }
}
