<?php

namespace App\Support\Email;

use App\Mailbox;
use App\Support\AccountManager\UserManager;
use App\UserRecoveryEmail;

/**
 * The emails shown for a user in the panel. Without an organization address the user's `mail`
 * is their email (it signs them in and is where a reset goes), and they can add a secondary
 * recovery email next to it. With one, `mail` is that address, and what the person gave before
 * is their recovery email.
 */
class UserEmails
{
    /**
     * @return array{personal_email: string|null, org_email: string|null, recovery_missing: bool, secondary_recovery: string|null}
     */
    public static function for(UserManager $user): array
    {
        $username = (string) $user->attribute('username');

        return self::lookup([$username], [$username => (string) $user->attribute('email')])[$username];
    }

    /**
     * For several users at once, with two queries however many there are.
     *
     * @param  array<int, string>  $usernames
     * @param  array<string, string>  $mail  Each user's `mail`, by username
     * @return array<string, array{personal_email: string|null, org_email: string|null, recovery_missing: bool, secondary_recovery: string|null}>
     */
    public static function lookup(array $usernames, array $mail): array
    {
        $mailboxes = Mailbox::whereIn('username', $usernames)->where('status', Mailbox::ACTIVE)->get()->keyBy('username');
        $recovery = UserRecoveryEmail::whereIn('username', $usernames)->pluck('email', 'username');

        $emails = [];

        foreach ($usernames as $username) {
            $mailbox = $mailboxes->get($username);

            // Someone with an organization address needs a recovery email that works next to it
            $emails[$username] = $mailbox
                ? [
                    'personal_email' => $recovery->get($username),
                    'org_email' => $mailbox->address,
                    'recovery_missing' => self::unusable($recovery->get($username), $mailbox->address),
                    'secondary_recovery' => null,
                ]
                : [
                    'personal_email' => $mail[$username] ?? null,
                    'org_email' => null,
                    'recovery_missing' => false,
                    // Only one that is of any use next to the sign-in email: not the same address and not hosted here
                    'secondary_recovery' => self::unusable($recovery->get($username), $mail[$username] ?? '') ? null : $recovery->get($username),
                ];
        }

        return $emails;
    }

    private static function unusable(?string $recovery, string $address): bool
    {
        return $recovery === null || strcasecmp($recovery, $address) === 0 || HostedDomains::isHosted($recovery);
    }
}
