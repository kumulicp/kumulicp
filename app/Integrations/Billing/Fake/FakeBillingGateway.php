<?php

namespace App\Integrations\Billing\Fake;

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

    public function component(): string
    {
        return 'FakePaymentMethod';
    }

    public function isBillable(): bool
    {
        return false;
    }

    public function intent(): array
    {
        return ['client_secret' => null];
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

    public function discount(): array
    {
        return [];
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return ! empty($this->organization->setting('fake_payment_method'));
    }

    public function updateDefaultPaymentMethod(string $payment_method): void
    {
        $data = json_decode($payment_method, true);
        if (! $data) {
            return;
        }

        $card_number = preg_replace('/\D/', '', $data['card_number'] ?? '');

        $this->organization->updateSetting('fake_payment_method', [
            'last4' => substr($card_number, -4),
            'exp_month' => $data['exp_month'] ?? '',
            'exp_year' => $data['exp_year'] ?? '',
            'brand' => $this->detectCardBrand($card_number),
        ]);
        $this->organization->save();
    }

    public function deleteDefaultPaymentMethod(): void
    {
        $this->organization->updateSetting('fake_payment_method', null);
        $this->organization->save();
    }

    public function defaultPaymentMethodBrand()
    {
        return $this->organization->setting('fake_payment_method.brand') ?? '';
    }

    public function defaultPaymentMethod(): array
    {
        $method = $this->organization->setting('fake_payment_method') ?? [];

        return [
            'card' => [
                'brand' => $method['brand'] ?? '',
                'last4' => $method['last4'] ?? '',
                'exp_month' => $method['exp_month'] ?? '',
                'exp_year' => $method['exp_year'] ?? '',
            ],
        ];
    }

    public function defaultPaymentMethodBrandImage(): ?string
    {
        return null;
    }

    private function detectCardBrand(string $number): string
    {
        if (str_starts_with($number, '4')) {
            return 'Visa';
        }
        if (preg_match('/^5[1-5]/', $number)) {
            return 'Mastercard';
        }
        if (preg_match('/^3[47]/', $number)) {
            return 'American Express';
        }
        if (preg_match('/^6(?:011|5)/', $number)) {
            return 'Discover';
        }

        return 'Card';
    }
}
