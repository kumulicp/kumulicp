<?php

use App\Integrations\Billing\Stripe\StripeGateway;
use App\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Invoice;

/**
 * Cashier 16 builds upcomingInvoice() from Stripe's preview-invoice API, so
 * fields we read off it (next_payment_attempt, period_end) may be absent.
 * These wrap a real Stripe object in Cashier's Invoice, the same shape the
 * gateway receives, without calling Stripe.
 */
function previewInvoice(array $attributes): Invoice
{
    return new Invoice(
        Organization::factory()->make(),
        Stripe\Invoice::constructFrom($attributes),
    );
}

function fakeStripeSubscription(?Stripe\Invoice $upcoming, ?DateTimeInterface $current_period_end): object
{
    return new class($upcoming, $current_period_end)
    {
        public function __construct(private ?Stripe\Invoice $upcoming, private ?DateTimeInterface $current_period_end) {}

        public function upcomingInvoice()
        {
            return $this->upcoming;
        }

        public function currentPeriodEnd()
        {
            return $this->current_period_end;
        }
    };
}

it('formats the due date from next_payment_attempt', function () {
    $invoice = previewInvoice(['next_payment_attempt' => Carbon::parse('2027-03-15 12:00', 'UTC')->timestamp, 'period_end' => 1]);

    expect((new StripeGateway(Organization::factory()->make()))->upcomingInvoiceDueDate($invoice))->toBe('Mar 15, 2027');
});

it('falls back to period_end when the preview has no next_payment_attempt', function () {
    $invoice = previewInvoice(['next_payment_attempt' => null, 'period_end' => Carbon::parse('2027-04-01 12:00', 'UTC')->timestamp]);

    expect((new StripeGateway(Organization::factory()->make()))->upcomingInvoiceDueDate($invoice))->toBe('Apr 01, 2027');
});

it('reports Never rather than today when the preview has no date at all', function () {
    $gateway = new StripeGateway(Organization::factory()->make());

    expect($gateway->upcomingInvoiceDueDate(previewInvoice([])))->toBe('Never');
    expect($gateway->upcomingInvoiceDueDate(null))->toBe('Never');
});

it('uses the upcoming invoice period_end as the subscription period end', function () {
    $timestamp = Carbon::parse('2027-05-01 00:00', 'UTC')->timestamp;
    $subscription = fakeStripeSubscription(Stripe\Invoice::constructFrom(['period_end' => $timestamp]), Carbon::parse('2030-01-01'));

    $end = (new StripeGateway(Organization::factory()->make()))->subscriptionPeriodEnd($subscription);

    expect($end->timestamp)->toBe($timestamp);
});

it('falls back to the subscription period end when the preview has none', function () {
    $current = Carbon::parse('2027-06-01 00:00', 'UTC');
    $subscription = fakeStripeSubscription(null, $current);

    $end = (new StripeGateway(Organization::factory()->make()))->subscriptionPeriodEnd($subscription);

    expect($end->timestamp)->toBe($current->timestamp);
});

it('has no period end without a subscription or any period data', function () {
    $gateway = new StripeGateway(Organization::factory()->make());

    expect($gateway->subscriptionPeriodEnd(null))->toBeNull();
    expect($gateway->subscriptionPeriodEnd(fakeStripeSubscription(null, null)))->toBeNull();
});

it('has the subscription_items columns Cashier 16 writes for metered prices', function () {
    expect(Schema::hasColumns('subscription_items', ['meter_id', 'meter_event_name']))->toBeTrue();
});
