<?php

namespace Tests\Support\Billing;

use App\Contracts\BillingContract;
use App\Organization;
use App\Support\Facades\Organization as OrganizationFacade;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FakeBillingGateway implements BillingContract
{
    private ?Organization $organization;

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization ?? OrganizationFacade::account();
    }

    public function isBillable(): bool
    {
        return true;
    }

    public function update(): void {}

    public function cancel(): void {}

    public function sendInvoices(): void {}

    public function sendInvoice(float $price, string $description): void {}

    public function periodEnds(): ?Carbon
    {
        return null;
    }

    public function invoices(): Collection
    {
        return collect();
    }

    public function upcomingInvoice(): array
    {
        return [];
    }

    public function status(): string
    {
        return 'active';
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return true;
    }

    public function updateDefaultPaymentMethod(string $payment_method): void {}

    public function defaultPaymentMethodBrand()
    {
        return 'visa';
    }

    public function defaultPaymentMethod(): array
    {
        return ['card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]];
    }

    public function defaultPaymentMethodBrandImage(): ?string
    {
        return null;
    }
}
