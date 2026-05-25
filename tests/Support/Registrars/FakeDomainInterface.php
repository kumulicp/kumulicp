<?php

namespace Tests\Support\Registrars;

use App\Contracts\Registrar\RegistrarDomainContract;
use App\OrgDomain;
use App\Support\Domains\DomainManager;

class FakeDomainInterface extends DomainManager implements RegistrarDomainContract
{
    public function info(): array
    {
        return [
            'status' => 'active',
            'id' => $this->domain->id,
            'domain_name' => $this->domain->name,
            'owner_name' => 'Fake Owner',
            'is_owner' => true,
            'is_premium' => str_contains($this->domain->name, 'premium'),
            'created_date' => now()->toDate0String(),
            'expired_date' => now()->addYear()->toDateString(),
            'whois_guard' => [
                'enabled' => false,
                'id' => null,
                'expired_date' => null,
                'whois_guard_email' => null,
                'forwarded_to' => null,
                'last_auto_email_change_date' => null,
                'auto_email_change_frequency_days' => null,
            ],
        ];
    }

    public function transfer(string $epp_code): void
    {
        $this->domain->status = 'transferring';
        $this->domain->transfer_id = 'fake-transfer-'.uniqid();
        $this->domain->save();
    }

    public function renew(int $years): array
    {
        return ['price' => 9.99 * $years];
    }

    public function maxRenewalYears(): int
    {
        return 10;
    }

    public function reactivate(): array
    {
        return ['price' => 12.99];
    }

    public function updateDNS(): void {}

    public function pricing(): FakePricingInterface
    {
        return new FakePricingInterface(str_contains($this->domain->name, 'premium'));
    }

    public function extendedAttributes(array $attributes): ?array
    {
        return null;
    }
}
