<?php

namespace App\Services\Tickets\Integrations;

/**
 * Something that happened to an issue in an external tracker.
 */
final class InboundEvent
{
    public const STATE = 'state';

    public const COMMENT = 'comment';

    /**
     * @param  string  $kind  state|comment
     * @param  string  $issueId  The issue, as the tracker numbers it
     * @param  string  $key  Identifies this occurrence so a repeat is ignored
     * @param  string|null  $state  open|closed (for state changes)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $issueId,
        public readonly string $key,
        public readonly ?string $state = null,
        public readonly string $author = '',
        public readonly string $body = '',
    ) {}
}
