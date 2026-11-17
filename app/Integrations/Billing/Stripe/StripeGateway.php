<?php

namespace App\Integrations\Billing\Stripe;

use App\Contracts\BillingContract;
use App\Contracts\PlanContract;
use App\Notifications\OrganizationCancelledSubscription;
use App\Organization;
use App\Services\Billing\SubscriptionRequestBuilder;
use App\Support\Billing\SubscriptionLine;
use App\Support\Billing\SubscriptionRequest;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StripeGateway implements BillingContract
{
    private $organization;

    public function __construct(?Organization $organization = null)
    {
        $this->organization = $organization ?? OrganizationFacade::account();
    }

    public function component(): string
    {
        return 'StripePaymentMethod';
    }

    public function isBillable(): bool
    {
        $subscription = $this->organization->subscription('default');
        if (! $subscription) {
            return false;
        } elseif ($subscription->canceled()) {
            $subscription->stripe_status = 'canceled';
            $subscription->save();

            return false;
        }

        return true;
    }

    /**
     * Ended or cancelled subscriptions count: their invoices are still part of the account's history.
     */
    public function hasBillingHistory(): bool
    {
        return $this->organization->subscriptions()->exists();
    }

    public function setup(): void
    {
        $this->organization->createOrGetStripeCustomer();
    }

    public function intent(): array
    {
        $stripeCustomer = $this->organization->createOrGetStripeCustomer();
        $intent = $this->organization->createSetupIntent();

        return [
            'client_secret' => $intent->client_secret,
        ];
    }

    /**
     * Apply the request to the organization's Stripe subscription: create one
     * (with the trial, if the request has one) or swap the items of the existing
     * one. Does nothing until the organization has a payment method on file.
     */
    public function syncSubscription(SubscriptionRequest $request): bool
    {
        $organization = $request->organization;

        if (! $organization->hasDefaultPaymentMethod()) {
            return false;
        }

        $items = $this->stripeItems($request->lines);

        // If organization already has a subscription of any time (active, canceled, etc)
        if ($organization->subscribed('default') && ! $organization->subscription('default')->ended()) {

            // If subscription cancelled or if current subscription does not have a stripe plan, but stripe stil indicated organization is on grace period and pending subscription is same as previous subscription.
            if (! $organization->subscription('default')->active() && $organization->subscription('default')->onGracePeriod()) {
                $organization->subscription('default')->resume();
            }

            $organization_subscription = $organization->subscription('default');

            if ($request->discountId) {
                $organization_subscription->applyCoupon($request->discountId);
            }

            $organization_subscription->noProrate()->swap($items);

            return false;
        }

        // If not on any plan or original plan canceled, add new plan
        $new_subscription = $organization->newSubscription('default', array_keys($items));
        if ($request->discountId) {
            $new_subscription->withCoupon($request->discountId);
        }

        foreach ($items as $id => $details) {
            $new_subscription->quantity((int) $details['quantity'], $id);
        }

        // Trial period if no previous subscription
        if ($request->trialDays > 0) {
            $new_subscription->trialDays($request->trialDays);
        }
        $new_subscription->noProrate()->add();

        return true;
    }

    public function cancel(): void
    {
        if ($this->organization->subscribed('default')) {
            $this->organization->subscription('default')->cancel();

            $superaccount = Organization::where('type', 'superaccount')->first();
            $superaccount->notifyAdmins(new OrganizationCancelledSubscription($this->organization));
        }
    }

    public function discount(): array
    {
        $discount = $this->organization->subscription('default')?->discount()?->coupon();

        return $discount ? [
            'type' => $discount->isPercentage() ? 'percent' : 'amount',
            'amount' => $discount->isPercentage() ? $discount->percentOff().'%' : $discount->amountOff(),
        ] : [];
    }

    public function sendInvoices(): void
    {
        $subscription = $this->organization->subscription('default');
        // Check for all recent invoices to send to billing managers
        $recent_invoices = $this->organization->invoices(false, ['limit' => 1, 'created' => ['gt' => (new Carbon($subscription->invoice_sent_at))->timestamp, 'lt' => now()->timestamp]]);

        OrganizationFacade::setOrganization($this->organization);
        $billing_managers_notified = false;
        $invoice_num = 0;
        foreach ($recent_invoices as $invoice) {
            $billing_managers = AccountManager::users()->notifyBillingManagers($invoice);
            $billing_managers_notified = true;
            $invoice_num++;
        }

        if ($billing_managers_notified) {
            $subscription->invoice_sent_at = now();
            $subscription->save();

            Log::info(__('actions.notify.bill', ['num' => $invoice_num]), ['organization_id' => $this->organization->id]);
        }
    }

    public function sendInvoice(float $price, string $description): void
    {
        $stripe_price_format = number_format($price, 2, '', '');
        $description = $description;
        $stripeCharge = $this->organization->invoiceFor($description, $stripe_price_format);

        Log::info(__('actions.invoice', ['description' => $description, 'price' => $stripe_price_format]), ['organization_id' => $this->organization]);

        $recent_invoices = $this->organization->invoices(false, ['limit' => 1, 'created' => ['gt' => now()->subMinutes(10)->timestamp, 'lt' => now()->timestamp]]);

        foreach ($recent_invoices as $invoice) {
            $billing_managers = AccountManager::users()->notifyBillingManagers($invoice, type: 'custom', description: $description, price: $stripe_price_format);
        }
    }

    public function periodEnds(): ?Carbon
    {
        return $this->subscriptionPeriodEnd(Organization::account()->subscription('default'));
    }

    /**
     * When the subscription's current billing period ends.
     *
     * Cashier 16 builds the upcoming invoice from Stripe's preview-invoice
     * API, so its period_end is no longer guaranteed to be present. Fall back
     * to the subscription's own period end (read from its items) rather than
     * reporting no date at all.
     */
    public function subscriptionPeriodEnd($subscription): ?Carbon
    {
        if (! $subscription) {
            return null;
        }

        $period_end = $subscription->upcomingInvoice()?->period_end;

        if ($period_end) {
            return Carbon::createFromTimestamp($period_end);
        }

        $current_period_end = $subscription->currentPeriodEnd();

        return $current_period_end ? Carbon::instance($current_period_end) : null;
    }

    public function upcomingInvoice(): array
    {
        $upcoming_invoice = $this->organization->upcomingInvoice();

        return [
            'due_date' => $this->upcomingInvoiceDueDate($upcoming_invoice),
            'amount_due' => $upcoming_invoice ? '$'.($upcoming_invoice->amount_due / 100) : '$0.00',
            'status' => $this->status(),
        ];
    }

    /**
     * Format when the upcoming invoice is due.
     *
     * next_payment_attempt is not guaranteed on a preview invoice, and
     * date() silently turns a null timestamp into "now", which would show
     * today's date as the due date. Fall back to the invoice's period_end,
     * and only then report 'Never'.
     */
    public function upcomingInvoiceDueDate($upcoming_invoice): string
    {
        if (! $upcoming_invoice) {
            return 'Never';
        }

        // ?: rather than ??: Cashier's Invoice proxies properties through
        // __get() without an __isset(), so ?? would always see them as unset.
        $timestamp = $upcoming_invoice->next_payment_attempt ?: $upcoming_invoice->period_end;

        return $timestamp ? date('M d, Y', $timestamp) : 'Never';
    }

    public function invoices(): Collection
    {
        $invoices = $this->organization->invoices(false, ['limit' => 10]);

        return $invoices->map(function ($invoice) {
            return [
                'total' => $invoice->total(),
                'created' => date('F j, Y', $invoice->created),
                'status' => $invoice->status == 'paid' ? 'Paid' : 'Not paid',
                'download' => "/subscription/invoice/{$invoice->id}/download",
            ];
        });
    }

    public function status(string $type = 'label'): string
    {
        $key = $this->statusKey();

        if ($type !== 'label') {
            return $key;
        }

        return __('labels.'.($key === 'ended' ? 'cancelled' : $key));
    }

    private function statusKey(): string
    {
        // Read the subscription itself rather than gating on subscribed():
        // Cashier's subscribed() is only true while the subscription is valid
        // (active, on trial or in its grace period), so it is false once a
        // subscription has ended -- which made the 'ended' result unreachable
        // and left lapsed organizations reporting 'active' forever.
        $subscription = $this->organization->subscription('default');

        if (! $subscription) {
            return 'active';
        }

        if ($subscription->onGracePeriod()) {
            return 'grace_period';
        }

        if ($subscription->ended()) {
            return 'ended';
        }

        if ($subscription->onTrial()) {
            return 'trial_period';
        }

        return 'active';
    }

    /**
     * The organization's billable items as Stripe subscription items
     * ([price id => ['quantity' => n]]).
     *
     * @deprecated what is billed is worked out for every driver by SubscriptionRequestBuilder
     */
    public function stripePricing(): array
    {
        return $this->stripeItems(app(SubscriptionRequestBuilder::class)->build($this->organization)->lines);
    }

    /**
     * @param  Collection<int, SubscriptionLine>  $lines
     * @return array<string, array{quantity: int}>
     */
    private function stripeItems(Collection $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            $items[$line->externalId] = ['quantity' => $line->quantity];
        }

        return $items;
    }

    /**
     * Collapse the billable lines of the given plans into
     * [price_id => ['quantity' => n]], summing lines that share a price.
     *
     * @param  iterable<PlanContract>  $plans
     * @return array<string, array{quantity: int}>
     */
    public function pricingFromPlans(iterable $plans): array
    {
        $pricing = [];

        foreach ($plans as $plan) {
            foreach ($plan->pricingOptions() as $entity => $info) {
                // An app instance's optional features arrive as one nested
                // 'features' entry (feature name => line) instead of a single
                // line. Reading that entry as a line itself found no price_id,
                // so active priced features were never added to the invoice.
                $lines = $entity === 'features' ? $info : [$info];

                foreach ($lines as $line) {
                    $price_id = Arr::get($line, 'price_id');
                    $quantity = Arr::get($line, 'quantity', 0);

                    if ($price_id && $quantity > 0) {
                        $pricing[$price_id]['quantity'] = ($pricing[$price_id]['quantity'] ?? 0) + $quantity;
                    }
                }
            }
        }

        return $pricing;
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return $this->organization->hasDefaultPaymentMethod();
    }

    public function updateDefaultPaymentMethod(string $payment_method): void
    {
        $this->organization->updateDefaultPaymentMethod($payment_method);
    }

    public function defaultPaymentMethodBrand()
    {
        $brand = Arr::get($this->defaultPaymentMethod(), 'card.brand');
        if ($this->hasDefaultPaymentMethod() && $brand) {
            $image_location = '/images/cards/'.$brand.'.png';
            if (Storage::exists($image_location)) {
                return $image_location;
            }
        }

        return '';
    }

    public function defaultPaymentMethod(): array
    {
        $payment_method = $this->organization->defaultPaymentMethod();

        return [
            'card' => [
                'brand' => $payment_method?->card->brand,
                'last4' => $payment_method?->card->last4,
                'exp_month' => $payment_method?->card->exp_month,
                'exp_year' => $payment_method?->card->exp_year,
            ],
        ];
    }

    public function defaultPaymentMethodBrandImage(): ?string
    {
        $brand = Arr::get($this->defaultPaymentMethod(), 'card.brand');
        if ($this->hasDefaultPaymentMethod() && $brand) {
            $image_location = '/images/cards/'.$brand.'.png';
            if (Storage::exists($image_location)) {
                return $image_location;
            }
        }

        return null;
    }

    public function deleteDefaultPaymentMethod(): void
    {
        $this->organization->defaultPaymentMethod()->delete();
    }
}
