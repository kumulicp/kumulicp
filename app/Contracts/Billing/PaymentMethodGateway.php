<?php

namespace App\Contracts\Billing;

/**
 * Optional: for engines where the customer saves a payment method in the app
 * (a card form). Engines that collect payment on their own hosted pages
 * implement SubscriptionGateway only; BillingService then treats the app as
 * having nothing to collect.
 */
interface PaymentMethodGateway
{
    /** Name of the front-end component that renders the payment method form */
    public function component(): string;

    public function intent(): array;

    public function hasDefaultPaymentMethod(): bool;

    public function updateDefaultPaymentMethod(string $payment_method): void;

    public function deleteDefaultPaymentMethod(): void;

    public function defaultPaymentMethodBrand();

    public function defaultPaymentMethod(): array;

    public function defaultPaymentMethodBrandImage(): ?string;
}
