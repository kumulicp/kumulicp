<?php

namespace App\Support\Email;

use App\OrgDomain;
use Illuminate\Support\Str;

class HostedDomains
{
    /**
     * Whether this server hosts, or is about to host, the mail for the domain of an address.
     * Any organization's domain counts: a recovery email there would be read from the very
     * server the person is locked out of.
     */
    public static function isHosted(string $address): bool
    {
        if (! str_contains($address, '@')) {
            return false;
        }

        $domain = Str::lower(Str::afterLast($address, '@'));

        return OrgDomain::whereRaw('lower(name) = ?', [$domain])
            ->where('email_status', '!=', 'disabled')
            ->exists();
    }
}
