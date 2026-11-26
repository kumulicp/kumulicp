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

    /** Whether taking a user's mailbox away fails, as it does when the server is down */
    public bool $failing_delete = false;

    /** @var array<string, string> The standalone mailboxes it holds, by address, with their name */
    public array $emails = [];

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
        if ($this->failing_delete) {
            throw new \RuntimeException('The mail server is unavailable');
        }

        $this->deleted[] = (string) $user->attribute('username');
    }

    public function emailList(OrgDomain $domain): array
    {
        if ($this->failing) {
            throw new \RuntimeException('The mail server is unavailable');
        }

        $rows = [];

        foreach ($this->emails as $address => $name) {
            if (str_ends_with($address, '@'.$domain->name)) {
                $rows[] = ['email' => $address, 'name' => $name, 'username' => strstr($address, '@', true)];
            }
        }

        return $rows;
    }

    public function existsEmail(OrgDomain $domain, string $address): bool
    {
        return isset($this->emails[$address]);
    }

    public function addEmail(OrgDomain $domain, string $name, string $username, string $password, ?string $type = null): void
    {
        if ($this->failing) {
            throw new \RuntimeException('The mail server is unavailable');
        }

        $this->emails[$username.'@'.$domain->name] = $name;
    }

    public function updateEmail(OrgDomain $domain, string $name, string $address, ?string $password = null): void
    {
        $this->emails[$address] = $name;
    }

    public function deleteEmail(OrgDomain $domain, string $address): void
    {
        unset($this->emails[$address]);
    }
}
