<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The part of a mailbox address before the @.
 *
 * Lowercase letters and digits, with single dots, hyphens or underscores between them
 * (jane.doe, j-doe, jane_doe). No `+`: mail servers read `name+tag` as a tag on `name`,
 * so a mailbox called that could never be reached. No leading, trailing or doubled
 * separators, and 64 characters at most (the limit mail servers apply).
 */
class EmailLocalPart implements ValidationRule
{
    public const MAX_LENGTH = 64;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > self::MAX_LENGTH || ! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $value)) {
            $fail('messages.rule.email_local_part')->translate();
        }
    }
}
