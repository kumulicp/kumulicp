<?php

namespace App\Contracts\Registrar;

interface RegistrarDomainContract
{
    public function info(): array;

    public function transfer(string $epp_code): void;

    public function renew(int $years): array;

    public function maxRenewalYears(): int;

    public function reactivate(): array;

    public function updateDNS(): void;

    /**
     * Whether there is anything to publish. The registrar replaces the whole record list, so a domain with
     * nothing to publish should be left as the registrar made it, not sent an empty one.
     */
    public function hasDnsRecords(): bool;

    public function pricing();

    public function extendedAttributes(array $attributes): ?array;
}
