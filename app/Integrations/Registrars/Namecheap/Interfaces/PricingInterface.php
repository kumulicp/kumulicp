<?php

namespace App\Integrations\Registrars\Namecheap\Interfaces;

use App\Contracts\Registrar\RegistrarPricingContract;
use App\Exceptions\DomainRegistrationException;
use App\Integrations\Registrars\Namecheap\API\Users;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Tld;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class PricingInterface implements RegistrarPricingContract
{
    private $tld;

    private $organization;

    private $price = 0;

    private $domain_info;

    public function __construct(Tld $tld, string $domain_name, ?array $domain_info = null)
    {
        $this->organization = Organization::account();
        $this->tld = $tld;

        if (! $domain_info) {
            $domain_info = Domain::registrar($tld)->check($domain_name);
        }
        $this->domain_info = $domain_info;
    }

    public function isPremium(): bool
    {
        return (bool) Arr::get($this->domain_info, 'is_premium_name', false);
    }

    public function premiumPrice(): float
    {
        return (float) Arr::get($this->domain_info, 'premium_registration_price', 0);
    }

    public function registrationPrice(int $years = 1): float
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return $years === 1 ? (float) $this->tld->register_price : $this->calculatePrice($this->registrationPrices(), $years);
    }

    public function registrationPrices(): array
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return json_decode($this->tld->register_prices, true);
    }

    public function transferPrice(int $years = 1): float
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return $years === 1 ? (float) $this->tld->transfer_price : $this->calculatePrice($this->transferPrices(), $years);
    }

    public function transferPrices(): array
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return json_decode($this->tld->transfer_prices, true);
    }

    public function renewPrice(int $years = 1): float
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return $years === 1 ? (float) $this->tld->renew_price : $this->calculatePrice($this->renewPrices(), $years);
    }

    public function renewPrices(): array
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return json_decode($this->tld->renew_prices, true);
    }

    public function reactivatePrice(): float
    {
        if ($this->needsPriceUpdate()) {
            $this->updatePrices();
        }

        return (float) $this->tld->reactivate_price;
    }

    public function needsPriceUpdate()
    {
        return (
            (! $this->tld->register_price && $this->tld->is_api_registerable)
            || (! $this->tld->register_prices && $this->tld->is_api_registerable)
            || (! $this->tld->transfer_price && $this->tld->is_api_transferable)
            || (! $this->tld->transfer_prices && $this->tld->is_api_transferable)
            || (! $this->tld->renew_price && $this->tld->is_api_renewable)
            || (! $this->tld->renew_prices && $this->tld->is_api_renewable)
            || (! $this->tld->reactivate_price)
        ) || now() > (new Carbon($this->tld->updated_at))->addDays(7);
    }

    public function updatePrices()
    {
        $users = new Users($this->organization);
        $users_response = $users->pricing('categories', $this->tld->name);
        if (! $users->hasError()) {

            // Update 1st year register price
            $this->tld->register_price = array_values($users_response['register'][$this->tld->name])[0]['YourPrice'];

            // Update register prices
            foreach ($users_response['register'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }

            $this->tld->register_prices = json_encode($prices);

            // Update 1st year transfer price
            $this->tld->transfer_price = array_values($users_response['transfer'][$this->tld->name])[0]['YourPrice'];

            // Update transfer_prices
            foreach ($users_response['transfer'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }
            $this->tld->transfer_prices = json_encode($prices);

            // Update 1st year renew price
            $this->tld->renew_price = array_values($users_response['renew'][$this->tld->name])[0]['YourPrice'];

            // Update renew prices
            foreach ($users_response['renew'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }
            $this->tld->renew_prices = json_encode($prices);

            // Update 1st year reactivate price (only 1 year)
            $this->tld->reactivate_price = array_values($users_response['reactivate'][$this->tld->name])[0]['YourPrice'];
            $this->tld->save();
        } else {
            $error = json_decode($users->error(), true);
            if (is_array($error) && count($error) > 0 && $error[0]['Number'] == 2030280) {
                throw new DomainRegistrationException($error[0]['Description']);
            } else {
                throw new DomainRegistrationException('Failed to get pricing');
            }
        }
    }

    public function calculatePrice(array $prices, int $years)
    {
        $calculated_price = 0;
        foreach ($prices as $year => $price) {
            if ($year <= $years) {
                $calculated_price += $price;
            }
        }

        return $calculated_price;
    }
}
