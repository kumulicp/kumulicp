<?php

namespace App\Contracts\Tickets;

use App\Services\Tickets\Integrations\DeliveryResult;
use App\Services\Tickets\Integrations\IntegrationConfig;

/**
 * A driver that is simply told what happens (a webhook). It receives the
 * events the integration subscribed to.
 */
interface ReceivesEvents extends TicketIntegrationDriver
{
    /**
     * The events an administrator can choose from.
     *
     * @return list<string>
     */
    public function events(): array;

    /**
     * @param  string  $event  e.g. message.created
     * @param  string  $deliveryId  Stable for retries of the same delivery, so receivers can de-duplicate
     */
    public function send(IntegrationConfig $config, string $event, array $payload, string $deliveryId): DeliveryResult;
}
