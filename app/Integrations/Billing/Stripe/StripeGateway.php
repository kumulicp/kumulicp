<?php

namespace App\Integrations\Billing\Stripe;

use App\Contracts\BillingContract;
use App\Organization;
use App\Services\Organization\JointSubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization as OrganizationFacade;
use App\Support\Facades\Subscription;
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
        $stripeCustomer = $this->organization->createOrGetStripeCustomer();
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

    public function update(): void
    {
        $plan_pricing = $this->compileSubscriptionPricing();

        if ($this->organization->status !== 'deactivated' && count($plan_pricing) > 0 && $this->organization->hasDefaultPaymentMethod()) {
            // If organization already has a subscription of any time (active, canceled, etc)
            if ($this->organization->subscribed('default') && ! $this->organization->subscription('default')->ended()) {

                // If subscription cancelled or if current subscription does not have a stripe plan, but stripe stil indicated organization is on grace period and pending subscription is same as previous subscription.
                if (! $this->organization->subscription('default')->active() && $this->organization->subscription('default')->onGracePeriod()) {
                    $this->organization->subscription('default')->resume();
                }

                $organization_subscription = $this->organization->subscription('default');

                if ($this->organization->discount_id) {
                    $organization_subscription->applyCoupon($this->organization->discount_id);
                }

                $organization_subscription->noProrate()->swap($plan_pricing);
            }

            // If not on any plan or original plan canceled, add new plan
            else {
                // Extract stripe ids
                $ids = [];
                foreach ($plan_pricing as $id => $quantity) {
                    $ids[] = $id;
                }

                // Create new subscription
                $new_subscription = $this->organization->newSubscription('default', $ids);
                if ($this->organization->discount_id) {
                    $new_subscription->withCoupon($this->organization->discount_id);
                }

                foreach ($plan_pricing as $id => $details) {
                    $new_subscription->quantity((int) $details['quantity'], $id);
                }

                // Add trial period if no previous subscription
                if ($this->organization->setting('step') === 1) {
                    $superaccount = Organization::where('type', 'superaccount')->first();
                    $superaccount->notifyAdmins(new NewOrganizationSubscription);

                    $new_subscription->trialDays(30);
                }
                $new_subscription->noProrate()->add();
            }
        }
    }

    public function cancel(): void
    {
        if ($this->organization->subscribed('default')) {
            $this->subscription('default')->cancel();

            $superaccount = Organization::where('type', 'superaccount')->first();
            $superaccount->notifyAdmins(new OrganizationCancelledSubscription($this->organization->account()));
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
        $period_end = Organization::account()->subscription('default')?->upcomingInvoice()?->period_end;

        if ($period_end) {
            return \Carbon\Carbon($period_end);
        }
    }

    public function upcomingInvoice(): array
    {
        $upcoming_invoice = $this->organization->upcomingInvoice();

        return [
            'due_date' => $upcoming_invoice ? date('M d, Y', $upcoming_invoice->next_payment_attempt) : 'Never',
            'amount_due' => $upcoming_invoice ? '$'.($upcoming_invoice->amount_due / 100) : '$0.00',
            'status' => $this->status(),
        ];
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
        $status = $type === 'label' ? __('labels.active') : 'active';

        if ($this->organization->subscribed('default')) {
            if ($this->organization->subscription('default')->onGracePeriod()) {
                $status = $type === 'label' ? __('labels.grace_period') : 'grace_period';
            } elseif ($this->organization->subscription('default')->ended()) {
                $status = $type === 'label' ? __('labels.cancelled') : 'ended';
            } elseif ($this->organization->subscription('default')->onTrial()) {
                $status = $type === 'label' ? __('labels.trial_period') : 'trial_period';
            } elseif ($this->organization->subscription('default')->active()) {
                $status = $type === 'label' ? __('labels.active') : 'active';
            }
        }

        return $status;
    }

    private function compileSubscriptionPricing()
    {
        if ($this->organization->setting('include_in_parent_invoice') === true && ! is_null($this->organization->parent_organization)) {
            $this->organization = OrganizationFacade::setOrganization($this->organization->parent_organization);
            $subscription = new JointSubscriptionService($this->organization);
        } else {
            if ($this->organization->suborganizations()->count() > 0) {
                $subscription = new JointSubscriptionService($this->organization);
            } else {
                $subscription = Subscription::all();
            }
        }

        $pricing = [];

        foreach ($subscription->paidSubscriptions() as $subscription) {
            foreach ($subscription->pricingOptions() as $entity => $info) {
                $price_id = Arr::get($info, 'price_id');
                if ($price_id && $info['quantity'] > 0) {
                    if (Arr::has($pricing, "$price_id.quantity")) {
                        $pricing[$price_id]['quantity'] += $info['quantity'];
                    } else {
                        $pricing[$price_id]['quantity'] = $info['quantity'];
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
        if ($this->hasDefaultPaymentMethod() && $this->defaultPaymentMethod()->card->brand) {
            if ($this->defaultPaymentMethod()->card->brand) {
                $brand = $this->defaultPaymentMethod()->card->brand;
                $image_location = '/images/cards/'.$brand.'.png';
                if (Storage::exists($image_location)) {
                    return $image_location;
                }
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

        return false;
    }

    public function deleteDefaultPaymentMethod()
    {
        $this->organization->defaultPaymentMethod()->delete();
    }
}
