<?php

namespace App\Services\Tickets\Integrations;

final class TestResult
{
    public function __construct(public readonly bool $ok, public readonly string $message) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
