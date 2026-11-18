<?php

namespace App\Rules;

use App\Support\Email\HostedDomains;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A recovery email has to be somewhere other than the account itself: not the address the
 * account signs in with, and not a domain this server hosts mail for.
 */
class RecoveryEmail implements ValidationRule
{
    public function __construct(private string $mail) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (strcasecmp($value, $this->mail) === 0) {
            $fail('messages.rule.recovery_email_same')->translate();
        } elseif (HostedDomains::isHosted($value)) {
            $fail('messages.rule.recovery_email_hosted')->translate();
        }
    }
}
