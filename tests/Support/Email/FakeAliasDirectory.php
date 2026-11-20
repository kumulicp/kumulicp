<?php

namespace Tests\Support\Email;

use App\Mailbox;
use App\Services\Email\AliasDirectory;

/**
 * A directory held in memory, so aliases can be mirrored and synced without an LDAP server.
 */
class FakeAliasDirectory implements AliasDirectory
{
    public bool $enabled = true;

    /** @var array<string, array<int, string>> The aliases the directory holds, by mailbox address */
    public array $entries = [];

    /** @var array<int, string> Mailbox addresses the directory fails for */
    public array $failing = [];

    /** @var array<int, array{string, array<int, string>}> Every write that was made */
    public array $writes = [];

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function read(Mailbox $mailbox): ?array
    {
        $this->failIfNeeded($mailbox);

        return $this->entries[$mailbox->address] ?? null;
    }

    public function write(Mailbox $mailbox, array $aliases): bool
    {
        $this->failIfNeeded($mailbox);

        if (! array_key_exists($mailbox->address, $this->entries)) {
            return false;
        }

        $this->entries[$mailbox->address] = $aliases;
        $this->writes[] = [$mailbox->address, $aliases];

        return true;
    }

    private function failIfNeeded(Mailbox $mailbox): void
    {
        if (in_array($mailbox->address, $this->failing, true)) {
            throw new \RuntimeException('The directory is unavailable');
        }
    }
}
