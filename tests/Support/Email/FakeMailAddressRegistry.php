<?php

namespace Tests\Support\Email;

use App\Services\Email\MailAddressRegistry;

/**
 * Addresses held in memory, so rules that ask whether an address is taken can be tested without a directory.
 */
class FakeMailAddressRegistry implements MailAddressRegistry
{
    /** @var array<int, string> The addresses that are taken, lowercase */
    public array $taken = [];

    /** @var array<int, string> Every address that was asked about */
    public array $asked = [];

    /** @var array<string, string> The user an address belongs to, by address, for the ones that do */
    public array $owners = [];

    public function inUse(string $address, ?string $except_username = null): bool
    {
        $this->asked[] = $address;
        $address = strtolower($address);

        if ($except_username !== null && ($this->owners[$address] ?? null) === $except_username) {
            return false;
        }

        return in_array($address, $this->taken, true);
    }
}
