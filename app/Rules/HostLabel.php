<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class HostLabel implements Rule
{
    /**
     * @param  bool  $dnsRecord  Allow the wider host syntax used by raw DNS records (TXT, CNAME, ...):
     *                           dotted names, underscores (e.g. mta._domainkey, _dmarc) and a leading wildcard label.
     */
    public function __construct(private bool $dnsRecord = false) {}

    public function passes($attribute, $value): bool
    {
        // Allow special DNS notation used by the subdomain manager
        if (in_array($value, ['@', '*'])) {
            return true;
        }

        if ($this->dnsRecord) {
            return $this->passesDnsRecord((string) $value);
        }

        // Standard DNS label: lowercase alphanumeric, internal hyphens only, max 63 chars
        return (bool) preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/', $value);
    }

    private function passesDnsRecord(string $value): bool
    {
        if (strlen($value) > 253) {
            return false;
        }

        $labels = explode('.', $value);

        // A wildcard is only valid as the left-most label (e.g. *.sub)
        if ($labels[0] === '*' && count($labels) > 1) {
            array_shift($labels);
        }

        foreach ($labels as $label) {
            // Underscores are valid in record names (DKIM, DMARC, SRV, ACME challenges, ...)
            if (! preg_match('/^[a-z0-9_]([a-z0-9_\-]{0,61}[a-z0-9_])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    public function message(): string
    {
        return __('messages.rule.host_label');
    }
}
