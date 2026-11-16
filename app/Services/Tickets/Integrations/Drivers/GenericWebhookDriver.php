<?php

namespace App\Services\Tickets\Integrations\Drivers;

use App\Contracts\Tickets\ReceivesEvents;
use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\DeliveryResult;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\IntegrationException;
use App\Services\Tickets\Integrations\OutboundHttp;
use App\Services\Tickets\Integrations\TestResult;

/**
 * Posts every subscribed event as signed JSON to any URL (n8n, Zapier, a chat
 * hook, your own service).
 *
 * Each request carries:
 *   X-KCP-Event       the event name, e.g. message.created
 *   X-KCP-Delivery    stable across retries, so the receiver can de-duplicate
 *   X-KCP-Signature   t=<unix time>,v1=<hex hmac-sha256 of "<t>.<raw body>">
 *
 * While a secret is being rotated the previous one is still accepted by
 * receivers: two v1= values are sent and either may be checked.
 */
class GenericWebhookDriver implements ReceivesEvents
{
    /** Every event a webhook can subscribe to. */
    public const EVENTS = ['ticket.created', 'message.created', 'status.changed', 'assigned', 'time.logged'];

    public function __construct(private OutboundHttp $http) {}

    public static function key(): string
    {
        return 'generic_webhook';
    }

    public function label(): string
    {
        return 'Webhook (signed JSON)';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'url',
                'type' => 'url',
                'label' => 'Endpoint URL',
                'help' => 'Must be https. Events are POSTed here as JSON.',
                'required' => true,
            ],
            [
                'key' => 'secret',
                'type' => 'secret',
                'label' => 'Signing secret',
                'help' => 'Generated for you. Use it to check the X-KCP-Signature header.',
                'required' => true,
                'generated' => true,
            ],
        ];
    }

    public function capabilities(): array
    {
        return [Capability::EVENT_PUSH];
    }

    public function events(): array
    {
        return self::EVENTS;
    }

    public function testConnection(IntegrationConfig $config): TestResult
    {
        try {
            $result = $this->send($config, 'ping', [
                'event' => 'ping',
                'occurred_at' => now()->toIso8601String(),
                'message' => 'Test from the support desk. No ticket data is included.',
            ], 'test-'.bin2hex(random_bytes(4)));

            return TestResult::ok("The endpoint answered {$result->status}.");
        } catch (IntegrationException $e) {
            return TestResult::failed($e->getMessage());
        }
    }

    public function send(IntegrationConfig $config, string $event, array $payload, string $deliveryId): DeliveryResult
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = time();

        $response = $this->http->send('POST', $config->string('url'), [
            'body' => $body,
            'content_type' => 'application/json',
            'headers' => [
                'X-KCP-Event' => $event,
                'X-KCP-Delivery' => $deliveryId,
                'X-KCP-Signature' => $this->signature($config, $timestamp, $body),
            ],
        ], $config->allowInternal());

        return new DeliveryResult($response->status(), mb_substr(trim($response->body()), 0, 2000));
    }

    /**
     * The header value. Public so receivers' libraries and tests can reproduce it.
     */
    public function signature(IntegrationConfig $config, int $timestamp, string $body): string
    {
        $parts = ['t='.$timestamp, 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $config->string('secret'))];

        $previous = $config->string('previous_secret');
        $until = (int) $config->get('previous_secret_until', 0);

        if ($previous !== '' && $until > $timestamp) {
            $parts[] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $previous);
        }

        return implode(',', $parts);
    }
}
