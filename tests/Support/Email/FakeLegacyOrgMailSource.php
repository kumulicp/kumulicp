<?php

namespace Tests\Support\Email;

use App\Services\Email\LegacyOrgMailSource;

/**
 * Users with orgMail values held in memory (the CI directory has no such attribute).
 */
class FakeLegacyOrgMailSource implements LegacyOrgMailSource
{
    /** @var array<string, array<int, string>> The values, by username */
    public array $values = [];

    /** @var array<int, array{string, string}> What was cleared */
    public array $cleared = [];

    public function entries(?string $organization = null, ?string $username = null): array
    {
        $entries = [];

        foreach ($this->values as $user => $org_mail) {
            if ($username === null || $username === $user) {
                $entries[] = ['username' => $user, 'org_mail' => $org_mail];
            }
        }

        return $entries;
    }

    public function clear(string $username, string $address): void
    {
        $this->cleared[] = [$username, $address];
        $this->values[$username] = array_values(array_filter(
            $this->values[$username] ?? [],
            fn ($value) => strcasecmp($value, $address) !== 0,
        ));

        if ($this->values[$username] === []) {
            unset($this->values[$username]);
        }
    }
}
