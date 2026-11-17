<?php

namespace App\Rules;

use App\Services\Email\MailAddressRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * The local part of a new mailbox address, checked as the full address
 * (local part + the chosen domain) against every address on the server.
 */
class MailAddressAvailable implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = auth()->user()?->organization?->domains()->find($this->data['domain'] ?? null);

        // A missing or foreign domain is reported by the domain's own rule
        if (! $domain || ! is_string($value)) {
            return;
        }

        $address = Str::lower($value.'@'.$domain->name);

        if (app(MailAddressRegistry::class)->inUse($address)) {
            $fail('messages.rule.ldap_email_not_exists')->translate();
        }
    }
}
