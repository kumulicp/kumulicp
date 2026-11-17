<?php

namespace Tests\Support\Email;

use App\Services\Email\RecoveryEmailDirectory;

/**
 * A directory held in memory, so the recovery email sync can be tested without an LDAP server.
 */
class FakeRecoveryEmailDirectory implements RecoveryEmailDirectory
{
    public bool $enabled = true;

    /** @var array<string, string|null> What the directory holds, by username */
    public array $entries = [];

    /** @var array<int, string> Usernames the directory fails for */
    public array $failing = [];

    /** @var array<int, array{string, string|null}> Every write that was made */
    public array $writes = [];

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function read(string $username): ?array
    {
        $this->failIfNeeded($username);

        return array_key_exists($username, $this->entries) ? ['value' => $this->entries[$username]] : null;
    }

    public function write(string $username, ?string $email): bool
    {
        $this->failIfNeeded($username);

        if (! array_key_exists($username, $this->entries)) {
            return false;
        }

        $this->entries[$username] = $email;
        $this->writes[] = [$username, $email];

        return true;
    }

    public function usernames(): array
    {
        return array_keys(array_filter($this->entries, fn ($value) => $value !== null && $value !== ''));
    }

    private function failIfNeeded(string $username): void
    {
        if (in_array($username, $this->failing, true)) {
            throw new \RuntimeException('The directory is unavailable');
        }
    }
}
