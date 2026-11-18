<?php

namespace App\Services\Email;

use App\Notifications\ResetPassword;
use App\Notifications\UserCreated;
use App\Support\Email\HostedDomains;
use App\UserRecoveryEmail;
use Illuminate\Auth\Notifications\ResetPassword as LaravelResetPassword;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Str;

/**
 * The recovery email of an account: the address a password reset is sent to, kept
 * apart from the address the account is known by (`mail`).
 *
 * The database is the source of truth. With LDAP and an attribute mapped for it, the
 * value is also written to the directory so other systems can read it, and sync()
 * puts it right again if the two ever drift apart.
 *
 * A recovery email is only any use when it is *usable*: it exists, it is not the same
 * address as `mail` (a reset to the address you can't open helps nobody), and it is not
 * on a domain this server hosts (the mail would come from the server you are locked
 * out of). Anything else falls back to `mail`.
 */
class RecoveryEmailService
{
    public function __construct(private RecoveryEmailDirectory $directory) {}

    public function get(string $username): ?string
    {
        return UserRecoveryEmail::where('username', $username)->value('email');
    }

    /**
     * Saves the recovery email as given, without asking the person to confirm it (null
     * or blank removes it), and mirrors it to the directory. For the panel's own changes:
     * the address an account was created with, or the one a switch to an organization
     * address moves out of `mail`. A directory that can't be reached doesn't undo the
     * save: the database is the truth, and sync() copies it over later.
     */
    public function set(string $username, ?string $email, bool $verified = false): void
    {
        $email = $email === null ? '' : Str::lower(trim($email));

        if ($email === '') {
            $this->forget($username);

            return;
        }

        UserRecoveryEmail::updateOrCreate(['username' => $username], [
            'email' => $email,
            'verified_at' => $verified ? now() : null,
            'pending_email' => null,
            'pending_requested_at' => null,
            'previous_email' => null,
            'active_from' => null,
        ]);

        $this->mirror($username, $email);
    }

    public function forget(string $username): void
    {
        UserRecoveryEmail::where('username', $username)->delete();

        $this->mirror($username, null);
    }

    /**
     * The recovery email, if it is of any use next to the address the account signs in with.
     */
    public function usable(string $username, string $mail): ?string
    {
        return $this->check($this->get($username), $mail);
    }

    /**
     * Where a password reset or an invite goes: the recovery email when it is usable,
     * otherwise `mail`. A recovery email that only just replaced another one is not used
     * until the delay has passed; the one it replaced is used meanwhile.
     */
    public function destination(string $username, string $mail): string
    {
        $row = UserRecoveryEmail::where('username', $username)->first();

        if (! $row) {
            return $mail;
        }

        $address = $row->email;

        if ($row->previous_email && $row->active_from?->isFuture()) {
            $address = $row->previous_email;
        }

        return $this->check($address, $mail) ?? $mail;
    }

    /**
     * Where a notification to this account is sent. Password resets and invites (the ones a
     * person uses to get back into the account) go to the recovery email; everything else
     * goes to `mail`.
     */
    public function routeFor(BaseNotification $notification, string $username, string $mail): string
    {
        $recovers_access = $notification instanceof ResetPassword
            || $notification instanceof UserCreated
            || $notification instanceof LaravelResetPassword;

        return $recovers_access ? $this->destination($username, $mail) : $mail;
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

    private function check(?string $address, string $mail): ?string
    {
        if ($address === null || $address === '' || strcasecmp($address, $mail) === 0 || HostedDomains::isHosted($address)) {
            return null;
        }

        return $address;
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
