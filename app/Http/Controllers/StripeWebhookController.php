<?php

namespace App\Http\Controllers;

use App\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Console\WebhookCommand;
use Laravel\Cashier\Http\Controllers\WebhookController;

class StripeWebhookController extends WebhookController
{
    /**
     * Stripe events this endpoint acts on: the ones Cashier handles itself,
     * plus the ones handled below that Cashier doesn't subscribe to by default.
     *
     * @var list<string>
     */
    public const EVENTS = [
        ...WebhookCommand::DEFAULT_EVENTS,
        'invoice.payment_failed',
        'charge.dispute.created',
    ];

    /**
     * Handle a Stripe webhook call.
     *
     * Only active when Stripe is the configured billing driver; otherwise the
     * endpoint behaves as if it doesn't exist, so a stray or stale Stripe
     * endpoint can't change organization status under another billing driver.
     * Runs after Cashier's signature check, so unsigned requests still get 403.
     */
    public function handleWebhook(Request $request)
    {
        if (config('billing.default') !== 'stripe') {
            return response('Stripe is not the active billing driver.', 404);
        }

        return parent::handleWebhook($request);
    }

    /**
     * Get the organization by Stripe customer ID.
     *
     * Cashier documents this as returning the Billable trait, which isn't a
     * type phpstan can resolve properties/methods on; narrow it to the model.
     *
     * @param  string|null  $stripeId
     * @return Organization|null
     */
    protected function getUserByStripeId($stripeId)
    {
        /** @var Organization|null $organization */
        $organization = parent::getUserByStripeId($stripeId);

        return $organization;
    }

    /**
     * Handle customer subscription updated.
     *
     * Reconciles the organization's status against the subscription's
     * Stripe status after Cashier syncs the subscription row.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);

        if ($organization = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            $stripeStatus = $payload['data']['object']['status'] ?? null;

            if (in_array($stripeStatus, ['past_due', 'unpaid'], true) && $organization->status !== 'deactivated') {
                $organization->status = 'past_due';
                $organization->saveQuietly();
            } elseif (in_array($stripeStatus, ['active', 'trialing'], true) && $organization->status === 'past_due') {
                $organization->reactivate();
            }
        }

        return $response;
    }

    /**
     * Handle the cancellation of a customer subscription.
     *
     * Deactivates the organization once Cashier has marked the
     * subscription itself as canceled.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        if ($organization = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if ($organization->status !== 'deactivated') {
                $organization->deactivate();
            }
        }

        return $response;
    }

    /**
     * Handle a failed invoice payment.
     *
     * Marks the organization past_due — a grace-period state that does
     * not block access (see the 'active' gate) — pending Stripe's
     * dunning retries.
     */
    protected function handleInvoicePaymentFailed(array $payload)
    {
        if ($organization = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if ($organization->status !== 'deactivated') {
                $organization->status = 'past_due';
                $organization->saveQuietly();
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle a successful invoice payment.
     *
     * Recovers the organization from a past_due state.
     */
    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        if ($organization = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if ($organization->status === 'past_due') {
                $organization->reactivate();
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle a newly created charge dispute.
     *
     * Disputes need human review, so this only reports the event for
     * visibility rather than mutating the organization automatically.
     */
    protected function handleChargeDisputeCreated(array $payload)
    {
        if ($organization = $this->getUserByStripeId($payload['data']['object']['customer'] ?? null)) {
            Log::warning('Stripe dispute created', [
                'organization_id' => $organization->id,
                'organization_slug' => $organization->slug,
                'dispute_id' => $payload['data']['object']['id'] ?? null,
            ]);
        }

        return $this->successMethod();
    }
}
