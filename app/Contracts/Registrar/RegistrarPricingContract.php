<?php

namespace App\Contracts\Registrar;

interface RegistrarPricingContract
{
    /**
     * Checks if domain is a premium domain.
     */
    public function isPremium(): bool;

    /**
     * Get premium price for premium domains.
     *
     * @return bool
     */
    public function premiumPrice(): float;

    /**
     * Get registration price.
     */
    public function registrationPrice(int $years): float;

    /**
     * Get registration prices for each year.
     */
    public function registrationPrices(): array;

    /**
     * Get transfer price.
     */
    public function transferPrice(int $years): float;

    /**
     * Get transfer prices for each year.
     *
     * @return float
     */
    public function transferPrices(): array;

    /**
     * Get renew price.
     */
    public function renewPrice(int $years): float;

    /**
     * Get renew prices for each year.
     *
     * @return float
     */
    public function renewPrices(): array;

    /**
     * Get reactivation price.
     */
    public function reactivatePrice(): float;
}
