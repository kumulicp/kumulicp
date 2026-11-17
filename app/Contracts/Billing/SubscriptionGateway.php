<?php

namespace App\Contracts\Billing;

use App\Support\Billing\SubscriptionRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What every billing engine must do: keep an organization's subscription in
 * step with its plans, and report on it. Collecting payment details is a
 * separate, optional capability (see PaymentMethodGateway).
 */
interface SubscriptionGateway
{
    public function isBillable(): bool;

    /**
     * Make the organization's subscription match the request's lines.
     *
     * @return bool true when this call created a new subscription, false when it
     *              changed an existing one or did nothing
     */
    public function syncSubscription(SubscriptionRequest $request): bool;

    public function cancel(): void;

    public function sendInvoices(): void;

    public function sendInvoice(float $price, string $description): void;

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

    public function discount(): array;
}
