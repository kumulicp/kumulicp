<?php

namespace App\Support\Email;

use App\Mailbox;
use App\Support\AccountManager\UserManager;
use App\UserRecoveryEmail;

/**
 * The two emails shown for a user in the panel. Without an organization address the user's
 * `mail` is their email and there is no second one. With one, `mail` is that address, and
 * what the person gave before is their recovery email.
 */
class UserEmails
{
    /**
     * @return array{personal_email: string|null, org_email: string|null}
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
     * @return array<string, array{personal_email: string|null, org_email: string|null}>
     */
    public static function lookup(array $usernames, array $mail): array
    {
        $mailboxes = Mailbox::whereIn('username', $usernames)->where('status', Mailbox::ACTIVE)->get()->keyBy('username');
        $recovery = $mailboxes->isEmpty()
            ? collect()
            : UserRecoveryEmail::whereIn('username', $mailboxes->keys())->pluck('email', 'username');

        $emails = [];

        foreach ($usernames as $username) {
            $mailbox = $mailboxes->get($username);

            $emails[$username] = $mailbox
                ? ['personal_email' => $recovery->get($username), 'org_email' => $mailbox->address]
                : ['personal_email' => $mail[$username] ?? null, 'org_email' => null];
        }

        return $emails;
    }
}
