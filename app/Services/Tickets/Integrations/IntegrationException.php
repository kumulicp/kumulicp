<?php

namespace App\Services\Tickets\Integrations;

use RuntimeException;

/**
 * A delivery failed. `retryable` says whether trying again later could help
 * (the other side was down or busy) or not (it said no, or the settings are wrong).
 */
class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }

    public static function retryable(string $message, ?int $status = null, ?int $retryAfter = null, string $body = ''): self
    {
        return new self($message, true, $status, $retryAfter, $body);
    }

    public static function permanent(string $message, ?int $status = null, string $body = ''): self
    {
        return new self($message, false, $status, null, $body);
    }
}
