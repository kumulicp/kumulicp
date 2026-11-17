<?php

namespace App\Ldap;

use App\Ldap\Models\User;
use App\Services\Email\RecoveryEmailDirectory;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\Settings;

class LdapRecoveryEmailDirectory implements RecoveryEmailDirectory
{
    public function enabled(): bool
    {
        return LdapEmailAttributes::recoverySyncEnabled();
    }

    public function read(string $username): ?array
    {
        $user = $this->user($username);

        return $user ? ['value' => $user->getFirstAttribute($this->attribute())] : null;
    }

    public function write(string $username, ?string $email): bool
    {
        $user = $this->user($username);

        if (! $user) {
            return false;
        }

        $user->setAttribute($this->attribute(), $email === null ? [] : [$email]);
        $user->save();

        return true;
    }

    public function usernames(): array
    {
        $username_attribute = $this->usernameAttribute();

        return User::whereHas($this->attribute())
            ->get()
            ->map(fn (User $user) => (string) $user->getFirstAttribute($username_attribute))
            ->filter()
            ->values()
            ->all();
    }

    private function user(string $username): ?User
    {
        // Usernames are unique across the whole directory, so there is no need to know the organization
        $user = User::where($this->usernameAttribute(), '=', $username)->first();

        return $user instanceof User ? $user : null;
    }

    private function attribute(): string
    {
        return (string) LdapEmailAttributes::recoveryAttribute();
    }

    private function usernameAttribute(): string
    {
        return Settings::get('ldap_username', 'cn') ?: 'cn';
    }
}
