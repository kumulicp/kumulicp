<?php

namespace App\Support\Email;

class AddressDomains
{
    /**
     * Whether any of the addresses is at exactly this domain. Not case sensitive, and
     * `example.com` is not matched by `jane@myexample.com` or `jane@example.com.au`.
     *
     * @param  iterable<int, string>  $addresses
     */
    public static function hasAddressAt(iterable $addresses, string $domain): bool
    {
        $suffix = '@'.strtolower($domain);

        foreach ($addresses as $address) {
            // Values come from raw LDAP attributes, so don't trust the PHPDoc type at runtime
            // @phpstan-ignore function.alreadyNarrowedType
            if (is_string($address) && str_ends_with(strtolower($address), $suffix)) {
                return true;
            }
        }

        return false;
    }
}
