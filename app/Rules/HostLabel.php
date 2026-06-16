<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class HostLabel implements Rule
{
    public function passes($attribute, $value): bool
    {
        // Allow special DNS notation used by the subdomain manager
        if (in_array($value, ['@', '*'])) {
            return true;
        }

        // Standard DNS label: lowercase alphanumeric, internal hyphens only, max 63 chars
        return (bool) preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $value);
    }

    public function message(): string
    {
        return __('messages.rule.host_label');
    }
}
