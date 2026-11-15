<?php

namespace App\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface BillingContract
{
    public function isBillable(): bool;

    public function update(): void;

    public function cancel(): void;

    public function sendInvoices(): void;

    public function periodEnds(): ?Carbon;

    public function invoices(): Collection;

    public function upcomingInvoice(): array;

    /**
     * The organization's subscription status.
     *
     * With $type 'label' (the default) this is a translated, display-ready
     * string. Any other value returns the stable machine key instead --
     * 'active', 'trial_period', 'grace_period' or 'ended' -- which is what
     * code that makes decisions must compare against, never the label.
     */
    public function status(string $type = 'label'): string;

    public function component(): string;

    public function hasDefaultPaymentMethod(): bool;

    public function updateDefaultPaymentMethod(string $payment_method): void;

    public function deleteDefaultPaymentMethod(): void;

    public function defaultPaymentMethodBrand();

    public function defaultPaymentMethod(): array;

    public function defaultPaymentMethodBrandImage(): ?string;
}
