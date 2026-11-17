<?php

namespace App\Services\Email;

use App\Mailbox;
use App\User;
use Illuminate\Support\Str;

/**
 * The mailboxes the control panel has given out, and the emails its users sign in with.
 */
class DatabaseMailAddressRegistry implements MailAddressRegistry
{
    public function inUse(string $address, ?string $except_username = null): bool
    {
        $address = Str::lower($address);

        if (Mailbox::where('address', $address)->exists()) {
            return true;
        }

        // users.email is unique in the database, deleted users included
        return User::withTrashed()
            ->whereRaw('lower(email) = ?', [$address])
            ->when($except_username !== null, fn ($query) => $query->where('username', '!=', $except_username))
            ->exists();
    }
}
