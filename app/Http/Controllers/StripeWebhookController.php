<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;

class StripeWebhookController extends WebhookController
{
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
