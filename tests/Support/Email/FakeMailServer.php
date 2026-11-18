<?php

namespace Tests\Support\Email;

use App\OrgDomain;
use App\Support\AccountManager\UserManager;

/**
 * A mail server driver that records what it is asked to do for a user's mailbox.
 */
class FakeMailServer
{
    /** @var array<int, string> */
    public array $created = [];

    /** @var array<int, string> */
    public array $deleted = [];

    public bool $failing = false;

    public function createUserEmail(UserManager $user, OrgDomain $domain): array
    {
        if ($this->failing) {
            throw new \RuntimeException('The mail server is unavailable');
        }

        $this->created[] = (string) $user->attribute('username');

        return [];
    }

    public function deleteUserEmail(UserManager $user, ?string $email = null): void
    {
        $this->deleted[] = (string) $user->attribute('username');
    }
}
