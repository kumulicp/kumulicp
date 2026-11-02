<?php

namespace App\Services\Tickets\Integrations;

/**
 * What came of one delivery: the answer's status and a short note, and for
 * issue creation the new issue.
 */
final class DeliveryResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly string $summary = '',
        public readonly ?ExternalIssue $issue = null,
    ) {}

    /** Nothing needed doing (already in the desired state). */
    public static function nothingToDo(string $why): self
    {
        return new self(null, $why);
    }
}
