<?php

namespace App\Services\Tickets\Integrations;

/**
 * Looks up the addresses behind a host name. A class of its own so tests can
 * stand in for DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
