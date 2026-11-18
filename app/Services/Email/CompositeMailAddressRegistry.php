<?php

namespace App\Services\Email;

class CompositeMailAddressRegistry implements MailAddressRegistry
{
    /** @var array<int, MailAddressRegistry> */
    private array $registries;

    public function __construct(MailAddressRegistry ...$registries)
    {
        $this->registries = $registries;
    }

    public function inUse(string $address, ?string $except_username = null): bool
    {
        foreach ($this->registries as $registry) {
            if ($registry->inUse($address, $except_username)) {
                return true;
            }
        }

        return false;
    }
}
