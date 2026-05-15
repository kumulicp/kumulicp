<?php

namespace Tests\Support\Registrars;

use App\Contracts\Registrar\RegistrarPricingContract;

class FakePricingInterface implements RegistrarPricingContract
{
    public function isPremium(): bool
    {
        return false;
    }

    public function premiumPrice(): float
    {
        return 0.0;
    }

    public function registrationPrice(int $years = 1): float
    {
        return 9.99 * $years;
    }

    public function registrationPrices(): array
    {
        return array_map(fn ($y) => $this->registrationPrice($y), range(1, 10));
    }

    public function transferPrice(int $years = 1): float
    {
        return 8.99 * $years;
    }

    public function transferPrices(): array
    {
        return array_map(fn ($y) => $this->transferPrice($y), range(1, 10));
    }

    public function renewPrice(int $years = 1): float
    {
        return 9.99 * $years;
    }

    public function renewPrices(): array
    {
        return array_map(fn ($y) => $this->renewPrice($y), range(1, 10));
    }

    public function reactivatePrice(): float
    {
        return 12.99;
    }
}
