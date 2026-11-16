<?php

namespace App\Contracts\Tickets;

use App\Services\Tickets\Integrations\DeliveryResult;
use App\Services\Tickets\Integrations\ExternalIssue;
use App\Services\Tickets\Integrations\IntegrationConfig;

/**
 * A driver that keeps an issue in an external tracker in step with a ticket.
 *
 * Every method gets the delivery payload (see PayloadBuilder): the ticket, the
 * message or time entry the event is about, with the integration's privacy
 * options already applied. Body text arrives as Markdown that has been made
 * safe to post (no @mentions, no slash commands).
 *
 * Methods throw IntegrationException on failure; whether it is worth retrying
 * is part of the exception.
 */
interface TracksIssues extends TicketIntegrationDriver
{
    public function createIssue(IntegrationConfig $config, array $payload): DeliveryResult;

    public function addComment(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult;

    /**
     * @param  string  $state  open|closed
     */
    public function updateState(IntegrationConfig $config, ExternalIssue $issue, string $state): DeliveryResult;

    /**
     * Record time spent. Always callable: a tracker with real time tracking
     * (it declares Capability::TIME_TRACKING) logs it natively, one without
     * leaves a comment instead.
     */
    public function logTime(IntegrationConfig $config, ExternalIssue $issue, array $payload): DeliveryResult;
}
