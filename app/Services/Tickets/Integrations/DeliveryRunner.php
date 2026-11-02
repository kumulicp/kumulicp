<?php

namespace App\Services\Tickets\Integrations;

use App\Notifications\Tickets\IntegrationDisabled;
use App\Services\Tickets\TicketStaff;
use App\Support\Redactor;
use App\TicketDelivery;
use App\TicketIntegration;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs a delivery and decides what happens next: done, try again later, or
 * give up. Kept apart from the queue job so it can be run (and tested) directly.
 *
 *  - Deliveries to the same integration about the same ticket go in order, so a
 *    comment never overtakes the issue it belongs to.
 *  - Temporary trouble (the other side down or busy) is retried with growing
 *    waits, honouring Retry-After; a refusal is not retried.
 *  - An integration that keeps failing is switched off and staff are told.
 */
class DeliveryRunner
{
    /** How long a delivery waits for an earlier one on the same ticket. */
    private const ORDER_WAIT = 30;

    public function __construct(
        private DeliveryService $service,
        private TicketStaff $staff,
    ) {}

    /**
     * @return int|null Seconds to wait before running it again, or null when it is finished
     */
    public function run(int $deliveryId): ?int
    {
        $delivery = TicketDelivery::with('integration')->find($deliveryId);

        if (! $delivery || $delivery->status !== TicketDelivery::PENDING) {
            return null;
        }

        $integration = $delivery->integration;

        if (! $integration) {
            $this->giveUp($delivery, 'The integration was removed.');

            return null;
        }

        if (! $integration->enabled) {
            $this->giveUp($delivery, 'The integration is switched off. Switch it on and redeliver.');

            return null;
        }

        $earlier = TicketDelivery::where('integration_id', $delivery->integration_id)
            ->where('ticket_id', $delivery->ticket_id)
            ->where('status', TicketDelivery::PENDING)
            ->where('id', '<', $delivery->id)
            ->exists();

        if ($earlier) {
            return self::ORDER_WAIT;
        }

        $delivery->attempts++;
        $delivery->last_attempt_at = now();
        $delivery->save();

        try {
            $result = $this->service->perform($delivery);
        } catch (IntegrationException $e) {
            return $this->failed($delivery, $integration, $e);
        } catch (Throwable $e) {
            report($e);
            $this->countFailure($integration, 'Unexpected error');
            $this->giveUp($delivery, 'Unexpected error ('.class_basename($e).'). Staff with log access can see the details.');

            return null;
        }

        $delivery->status = TicketDelivery::DELIVERED;
        $delivery->response_code = $result->status;
        $delivery->response = $this->redact($result->summary);
        $delivery->error = null;
        $delivery->delivered_at = now();
        $delivery->save();

        $integration->consecutive_failures = 0;
        $integration->last_success_at = now();
        $integration->save();

        return null;
    }

    private function failed(TicketDelivery $delivery, TicketIntegration $integration, IntegrationException $e): ?int
    {
        $delivery->response_code = $e->status;
        $delivery->error = mb_substr($e->getMessage(), 0, 1000);
        $delivery->response = $this->redact($e->body);

        $this->countFailure($integration, $e->getMessage());

        $max = config('tickets.integrations.max_attempts');

        // The failure count above may have just switched the integration off
        if ($e->retryable && $delivery->attempts < $max && $integration->enabled) {
            $delivery->save();

            return $this->wait($delivery->attempts, $e->retryAfter);
        }

        $delivery->status = TicketDelivery::FAILED;
        $delivery->save();

        return null;
    }

    private function giveUp(TicketDelivery $delivery, string $reason): void
    {
        $delivery->status = TicketDelivery::FAILED;
        $delivery->error = $reason;
        $delivery->save();
    }

    private function wait(int $attempts, ?int $retryAfter): int
    {
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $backoff = config('tickets.integrations.backoff');

        return $backoff[min($attempts - 1, count($backoff) - 1)];
    }

    /**
     * One more failure in a row. Past the limit the integration is switched off
     * so a dead endpoint is not hammered, and staff are told.
     */
    private function countFailure(TicketIntegration $integration, string $lastError): void
    {
        $integration->consecutive_failures++;

        $limit = config('tickets.integrations.disable_after_failures');

        if ($integration->enabled && $integration->consecutive_failures >= $limit) {
            $integration->enabled = false;
            $integration->disabled_reason = mb_substr("Switched off after {$limit} failures in a row. Last error: {$lastError}", 0, 250);
            $integration->save();

            Notification::send($this->staff->users(), new IntegrationDisabled($integration));

            return;
        }

        $integration->save();
    }

    /**
     * What an answer looked like, for the log: JSON has anything that looks
     * like a secret masked, and everything is cut short.
     */
    private function redact(string $body): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $body = (string) json_encode(Redactor::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return mb_substr($body, 0, 2000);
    }
}
