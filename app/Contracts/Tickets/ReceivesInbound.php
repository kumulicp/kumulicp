<?php

namespace App\Contracts\Tickets;

use App\Services\Tickets\Integrations\InboundEvent;
use App\Services\Tickets\Integrations\IntegrationConfig;

/**
 * A tracker driver that the outside system can call back: when an issue is
 * closed or commented on there, the ticket hears about it.
 *
 * Both methods work on the raw body and lower-cased headers, so nothing here
 * depends on the web framework and the signature is checked against exactly the
 * bytes that were sent.
 */
interface ReceivesInbound extends TracksIssues
{
    /**
     * Is this call really from the tracker (signature or shared token)?
     *
     * @param  array<string, string>  $headers  Lower-case names
     */
    public function verifyInbound(string $body, array $headers, IntegrationConfig $config): bool;

    /**
     * What happened, or null if it is nothing we follow (other events, other
     * repositories, pull requests...).
     *
     * @param  array<string, string>  $headers  Lower-case names
     */
    public function parseInbound(string $body, array $headers, IntegrationConfig $config): ?InboundEvent;
}
