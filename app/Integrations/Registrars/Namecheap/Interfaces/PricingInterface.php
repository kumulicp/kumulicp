<?php

namespace App\Integrations\Registrars\Namecheap\Interfaces;

use App\Contracts\Registrar\RegistrarPricingContract;
use App\Exceptions\DomainRegistrationException;
use App\Exceptions\RegistrarUnavailableException;
use App\Integrations\Registrars\Namecheap\API\Users;
use App\Integrations\Registrars\Namecheap\Namecheap;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Tld;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class PricingInterface implements RegistrarPricingContract
{
    private $tld;

    private $organization;

    private $domain_info;

    // After a failed refresh, how long to go on with the cached prices before asking Namecheap again.
    // Without it every page load would wait out the connection timeout.
    private const OFFLINE_CACHE_KEY = 'registrar.namecheap.offline';

    private const OFFLINE_MINUTES = 5;

    public function __construct(Tld $tld, private string $domain_name, ?array $domain_info = null)
    {
        $this->organization = Organization::account();
        $this->tld = $tld;
        $this->domain_info = $domain_info;
    }

    /**
     * Whether Namecheap could not be reached the last time prices needed refreshing. Prices are then
     * whatever is stored for the TLD, and anything that has to talk to Namecheap should be disabled.
     */
    public function isOffline(): bool
    {
        return Cache::has(self::OFFLINE_CACHE_KEY);
    }

    // The availability check is a live call, so it only happens for the premium questions that need it,
    // not every time a price is read.
    private function domainInfo(): array
    {
        if (! $this->domain_info) {
            $this->domain_info = Domain::registrar($this->tld)->check($this->domain_name);
        }

        return $this->domain_info;
    }

    public function isPremium(): bool
    {
        return (bool) Arr::get($this->domainInfo(), 'is_premium_name', false);
    }

    public function premiumPrice(): float
    {
        return (float) Arr::get($this->domainInfo(), 'premium_registration_price', 0);
    }

    public function registrationPrice(int $years = 1): float
    {
        $this->refreshPrices('register_price');

        return $years === 1 ? (float) $this->tld->register_price : $this->calculatePrice($this->registrationPrices(), $years);
    }

    public function registrationPrices(): array
    {
        $this->refreshPrices('register_prices');

        return json_decode($this->tld->register_prices, true);
    }

    public function transferPrice(int $years = 1): float
    {
        $this->refreshPrices('transfer_price');

        return $years === 1 ? (float) $this->tld->transfer_price : $this->calculatePrice($this->transferPrices(), $years);
    }

    public function transferPrices(): array
    {
        $this->refreshPrices('transfer_prices');

        return json_decode($this->tld->transfer_prices, true);
    }

    public function renewPrice(int $years = 1): float
    {
        $this->refreshPrices('renew_price');

        return $years === 1 ? (float) $this->tld->renew_price : $this->calculatePrice($this->renewPrices(), $years);
    }

    public function renewPrices(): array
    {
        $this->refreshPrices('renew_prices');

        return json_decode($this->tld->renew_prices, true);
    }

    public function reactivatePrice(): float
    {
        $this->refreshPrices('reactivate_price');

        return (float) $this->tld->reactivate_price;
    }

    /**
     * Brings the stored prices up to date. If Namecheap can't be reached the stored prices are used as
     * they are, as long as the price that was asked for ($needed, a column of the TLD) is among them.
     *
     * @throws RegistrarUnavailableException when Namecheap can't be reached and nothing is stored yet
     */
    private function refreshPrices(string $needed): void
    {
        if (! $this->needsPriceUpdate()) {
            return;
        }

        $has_stored_prices = (bool) $this->tld->$needed;

        if ($this->isOffline()) {
            if ($has_stored_prices) {
                return;
            }

            throw new RegistrarUnavailableException(__('organization.domain.denied.registrar_unavailable'));
        }

        try {
            $this->updatePrices();
        } catch (RegistrarUnavailableException $e) {
            Cache::put(self::OFFLINE_CACHE_KEY, true, now()->addMinutes(self::OFFLINE_MINUTES));

            if (! $has_stored_prices) {
                throw $e;
            }
        }
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
            $prices = [];
            foreach ($users_response['register'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }

            $this->tld->register_prices = json_encode($prices);

            // Update 1st year transfer price
            $this->tld->transfer_price = array_values($users_response['transfer'][$this->tld->name])[0]['YourPrice'];

            // Update transfer_prices
            $prices = [];
            foreach ($users_response['transfer'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }
            $this->tld->transfer_prices = json_encode($prices);

            // Update 1st year renew price
            $this->tld->renew_price = array_values($users_response['renew'][$this->tld->name])[0]['YourPrice'];

            // Update renew prices
            $prices = [];
            foreach ($users_response['renew'][$this->tld->name] as $year => $price) {
                $prices[$year] = $price['YourPrice'];
            }
            $this->tld->renew_prices = json_encode($prices);

            // Update 1st year reactivate price (only 1 year)
            $this->tld->reactivate_price = array_values($users_response['reactivate'][$this->tld->name])[0]['YourPrice'];
            $this->tld->save();
        } elseif ($users->errorCode() === Namecheap::CONNECTION_ERROR) {
            throw new RegistrarUnavailableException(__('organization.domain.denied.registrar_unavailable'));
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
