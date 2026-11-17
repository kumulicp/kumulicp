<?php

namespace App\Services\Email;

use App\UserRecoveryEmail;
use Illuminate\Support\Str;

/**
 * The recovery email of an account: the address a password reset is sent to, kept
 * apart from the address the account is known by (`mail`).
 *
 * The database is the source of truth. With LDAP and an attribute mapped for it, the
 * value is also written to the directory so other systems can read it, and sync()
 * puts it right again if the two ever drift apart.
 */
class RecoveryEmailService
{
    public function __construct(private RecoveryEmailDirectory $directory) {}

    public function get(string $username): ?string
    {
        return UserRecoveryEmail::where('username', $username)->value('email');
    }

    /**
     * Saves the recovery email (null or blank removes it) and mirrors it to the
     * directory. A directory that can't be reached doesn't undo the save: the database
     * is the truth, and sync() copies it over later.
     */
    public function set(string $username, ?string $email): void
    {
        $email = $email === null ? '' : Str::lower(trim($email));

        if ($email === '') {
            $this->forget($username);

            return;
        }

        UserRecoveryEmail::updateOrCreate(['username' => $username], ['email' => $email]);

        $this->mirror($username, $email);
    }

    public function forget(string $username): void
    {
        UserRecoveryEmail::where('username', $username)->delete();

        $this->mirror($username, null);
    }

    /**
     * Makes the directory match the database, for every account that has a recovery
     * email. Accounts the directory has a value for but the database doesn't are only
     * reported: they may predate this, and nothing here should delete them.
     *
     * @return array{enabled: bool, checked: int, updated: int, unchanged: int, missing: array<int, string>, failed: array<string, string>, orphans: array<int, string>}
     */
    public function sync(): array
    {
        $summary = [
            'enabled' => $this->directory->enabled(),
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing' => [],
            'failed' => [],
            'orphans' => [],
        ];

        if (! $summary['enabled']) {
            return $summary;
        }

        UserRecoveryEmail::query()->orderBy('id')->chunkById(200, function ($rows) use (&$summary) {
            foreach ($rows as $row) {
                $summary['checked']++;

                try {
                    $current = $this->directory->read($row->username);

                    if ($current === null) {
                        $summary['missing'][] = $row->username;
                    } elseif (strcasecmp((string) $current['value'], $row->email) === 0) {
                        $summary['unchanged']++;
                    } elseif ($this->directory->write($row->username, $row->email)) {
                        $summary['updated']++;
                    } else {
                        $summary['missing'][] = $row->username;
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $summary['failed'][$row->username] = $e->getMessage();
                }
            }
        });

        try {
            $known = UserRecoveryEmail::pluck('username')->all();
            $summary['orphans'] = array_values(array_diff($this->directory->usernames(), $known));
        } catch (\Throwable $e) {
            report($e);
        }

        return $summary;
    }

    private function mirror(string $username, ?string $email): void
    {
        if (! $this->directory->enabled()) {
            return;
        }

        try {
            $this->directory->write($username, $email);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
