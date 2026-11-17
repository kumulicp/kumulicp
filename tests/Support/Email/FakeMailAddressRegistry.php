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

    public function inUse(string $address): bool
    {
        $this->asked[] = $address;

        return in_array(strtolower($address), $this->taken, true);
    }
}
