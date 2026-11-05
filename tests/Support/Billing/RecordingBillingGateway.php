<?php

namespace Tests\Support\Billing;

use App\Organization;
use App\Support\Facades\Organization as OrganizationFacade;

/**
 * A billing gateway that records what it was asked to do, so tests can assert
 * on how the app drives the billing driver (which organizations it asked,
 * what it called) without involving a payment provider.
 *
 * State is static because BillingService builds a fresh gateway per call;
 * call reset() at the start of every test.
 */
class RecordingBillingGateway extends FakeBillingGateway
{
    /** @var array<int, int> Organization ids that report isBillable() === true. */
    public static array $billable = [];

    /** @var array<int, int> Organization ids sendInvoices() was called for, in call order. */
    public static array $invoiced = [];

    /** @var array<int, int> Organization ids whose subscription has ended. */
    public static array $ended = [];

    private int $organization_id;

    public function __construct(?Organization $organization = null)
    {
        parent::__construct($organization);

        $this->organization_id = ($organization ?? OrganizationFacade::account())->id;
    }

    public static function reset(): void
    {
        self::$billable = [];
        self::$invoiced = [];
        self::$ended = [];
    }

    /**
     * Mirrors the real gateway's contract: a translated label by default, the
     * machine key ('ended') when asked for anything else.
     */
    public function status(string $type = 'label'): string
    {
        if (in_array($this->organization_id, self::$ended, true)) {
            return $type === 'label' ? 'Cancelled' : 'ended';
        }

        return 'active';
    }

    public function isBillable(): bool
    {
        return in_array($this->organization_id, self::$billable, true);
    }

    public function sendInvoices(): void
    {
        self::$invoiced[] = $this->organization_id;
    }
}
