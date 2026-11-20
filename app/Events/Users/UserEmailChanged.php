<?php

namespace App\Events\Users;

use App\Organization;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The email a user signs in with changed, from `$old_email` to `$new_email`. Apps that identify
 * people by email (ERPNext, say) listen for this, since the old address is what finds the
 * person in them and `UserUpdated` doesn't say what it was.
 *
 * Dispatched once the new address is saved, and only when it is a different address.
 */
class UserEmailChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public string $username,
        public string $old_email,
        public string $new_email,
    ) {}
}
