<?php

namespace Tests\Support;

use App\Services\Tickets\Integrations\HostResolver;

/**
 * Stands in for DNS so tests never touch the network: every host name resolves
 * to a public address unless a test says otherwise.
 */
class FakeHostResolver extends HostResolver
{
    /** @var array<string, list<string>> */
    public array $hosts = [];

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? ['93.184.216.34'];
    }
}
