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
     */
    public function transferPrices(): array;

    /**
     * Get renew price.
     */
    public function renewPrice(int $years): float;

    /**
     * Get renew prices for each year.
     */
    public function renewPrices(): array;

    /**
     * Get reactivation price.
     */
    public function reactivatePrice(): float;

    /**
     * Whether the registrar could not be reached the last time prices were refreshed. Prices are then
     * the stored ones, and anything that needs the registrar should not be offered.
     */
    public function isOffline(): bool;
}
