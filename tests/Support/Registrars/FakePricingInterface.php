<?php

namespace Tests\Support\Registrars;

use App\Contracts\Registrar\RegistrarPricingContract;

class FakePricingInterface implements RegistrarPricingContract
{
    public function __construct(private bool $premium = false) {}

    public function isPremium(): bool
    {
        return $this->premium;
    }

    public function premiumPrice(): float
    {
        return 99.99;
    }

    public function registrationPrice(int $years = 1): float
    {
        return 9.99 * $years;
    }

    /**
     * Year-keyed array matching how the real PricingInterface stores prices.
     * Keys are 1-based years; the real calculatePrice() sums entries where key <= $years.
     */
    public function registrationPrices(): array
    {
        return array_combine(range(1, 10), array_fill(0, 10, 9.99));
    }

    public function transferPrice(int $years = 1): float
    {
        return 8.99 * $years;
    }

    public function transferPrices(): array
    {
        return array_combine(range(1, 10), array_fill(0, 10, 8.99));
    }

    public function renewPrice(int $years = 1): float
    {
        return 9.99 * $years;
    }

    public function renewPrices(): array
    {
        return array_combine(range(1, 10), array_fill(0, 10, 9.99));
    }

    public function reactivatePrice(): float
    {
        return 12.99;
    }
}
