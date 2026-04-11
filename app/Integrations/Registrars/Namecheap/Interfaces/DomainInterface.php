<?php

namespace App\Integrations\Registrars\Namecheap\Interfaces;

use App\Contracts\Registrar\RegistrarDomainContract;
use App\Integrations\Registrars\Namecheap\API\Domains;
use App\Integrations\Registrars\Namecheap\API\DomainsDns;
use App\Integrations\Registrars\Namecheap\API\DomainsTransfer;
use App\Support\Domains\DomainManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class DomainInterface extends DomainManager implements RegistrarDomainContract
{
    public function info(): array
    {
        $domain = (new Domains($this->domain->organization))->info($this->domain->name);

        return [
            'status' => $domain['Status'],
            'id' => $domain['ID'],
            'domain_name' => $domain['DomainName'],
            'owner_name' => $domain['OwnerName'],
            'is_owner' => $domain['IsOwner'] == 'true',
            'is_premium' => $domain['IsPremium'] == 'true',
            'created_date' => $domain['CreatedDate'],
            'expired_date' => $domain['ExpiredDate'],
            'whois_guard' => [
                'enabled' => $domain['Whoisguard']['Enabled'],
                'id' => $domain['Whoisguard']['ID'],
                'expired_date' => $domain['Whoisguard']['ExpiredDate'],
                'whois_guard_email' => $domain['Whoisguard']['WhoisGuardEmail'],
                'forwarded_to' => $domain['Whoisguard']['ForwardedTo'],
                'last_auto_email_change_date' => $domain['Whoisguard']['LastAutoEmailChangeDate'],
                'auto_email_change_frequency_days' => $domain['Whoisguard']['AutoEmailChangeFrequencyDays'],
            ],
        ];
    }

    public function transfer(string $epp_code): void
    {
        $domains_transfer = new DomainsTransfer($this->domain->organization);
        $response = $domains_transfer->create($this->domain->name, $epp_code);
        if ($domains_transfer->hasError()) {
            $task->status = 'failed';
            $task->error_message = $domains_transfer->error();
            $task->error_code = 'domain_transfer_failed';
            $task->save();
        }

        if (array_key_exists('Transfer', $response) && $response['Transfer'] == true) {
            $this->domain->transfer_id = $response['TransferID'];
            $this->domain->transaction_id = $response['TransactionID'];
            $this->domain->order_id = $response['OrderID'];
            $this->domain->charged_amount = $response['ChargedAmount'];
            $this->domain->status = 'transferring';
            $this->domain->save();
        }
    }

    public function renew(int $years): array
    {
        $domains = new Domains($this->domain->organization);
        $renew = $domains->renew($this->domain, $years);

        $price = $this->pricing()->renewPrice($years) * $years;

        if (! $domains->hasError() && Arr::get($renew, 'Renew', false)) {
            $this->domain->expires_at = $renew['ExpiredDate'];
            $this->domain->save();
        }

        return [
            'price' => $price,
        ];
    }

    public function maxRenewalYears(): int
    {
        $max_years = count($this->pricing()->renewPrices());

        $expires = Carbon::createFromDate($this->domain->expires_at);

        return $max_years - $expires->diffInYears(Carbon::now());
    }

    public function reactivate(): array
    {
        $domains = new Domains($this->domain->organization);
        $domains->reactivate($domain_name);

        $price = $domains->reactivatePrice();

        return [
            'price' => $price,
        ];
    }

    public function updateDNS(): void
    {
        $dns = new DomainsDns($this->domain->organization);
        $dns_response = $dns->setHosts($this->domain);

        if ($dns->hasError() || ! Arr::get($dns_response, 'IsSuccess', false)) {
            throw new \Exception($dns->error());
        }
    }

    public function pricing()
    {
        return new PricingInterface($this->domain->tld, $this->domain->name);
    }

    public function extendedAttributes(array $attributes): ?array
    {
        $extended_attributes = new DomainExtendedAttributes($this->domain);

        return $extended_attributes->get($attributes);
    }
}
