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

    public function pricing();

    public function extendedAttributes(array $attributes): ?array;
}
