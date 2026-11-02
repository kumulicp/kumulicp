<?php

namespace App\Services\Tickets\Integrations;

/**
 * An issue in an external tracker.
 */
final class ExternalIssue
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $key = null,
        public readonly ?string $url = null,
        public readonly string $state = 'open',
    ) {}
}
