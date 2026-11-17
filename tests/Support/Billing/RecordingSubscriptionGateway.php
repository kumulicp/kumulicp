<?php

namespace Tests\Support\Billing;

use App\Contracts\Billing\SubscriptionGateway;
use App\Organization;
use App\Support\Billing\SubscriptionRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * A subscription-only driver (no payment methods in the app) that records the
 * requests it is asked to sync, for tests of BillingService.
 */
class RecordingSubscriptionGateway implements SubscriptionGateway
{
    /** @var list<SubscriptionRequest> */
    public static array $requests = [];

    /** What syncSubscription() reports: whether it created a new subscription */
    public static bool $creates = false;

    /** What cancel() reports: whether there was a subscription to cancel */
    public static bool $cancels = false;

    /** How many times cancel() was called */
    public static int $cancelCalls = 0;

    public function __construct(?Organization $organization = null) {}

    public static function reset(): void
    {
        self::$requests = [];
        self::$creates = false;
        self::$cancels = false;
        self::$cancelCalls = 0;
    }

    public function isBillable(): bool
    {
        return true;
    }

    public function syncSubscription(SubscriptionRequest $request): bool
    {
        self::$requests[] = $request;

        return self::$creates;
    }

    public function cancel(): bool
    {
        self::$cancelCalls++;

        return self::$cancels;
    }

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

    public function status(string $type = 'label'): string
    {
        return 'active';
    }

    public function discount(): array
    {
        return [];
    }
}
