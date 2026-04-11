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

    public function status(): string;

    public function hasDefaultPaymentMethod(): bool;

    public function updateDefaultPaymentMethod(string $payment_method): void;

    public function defaultPaymentMethodBrand();

    public function defaultPaymentMethod(): array;

    public function defaultPaymentMethodBrandImage(): ?string;
}
