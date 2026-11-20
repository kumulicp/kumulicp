<?php

namespace App\Services\Email;

use App\Events\Mailboxes\MailboxActivated;
use App\Events\Mailboxes\MailboxDeactivated;
use App\Events\Users\UserEmailChanged;
use App\Events\Users\UserUpdated;
use App\Mailbox;
use App\Notifications\PrimaryEmailChanged;
use App\OrgDomain;
use App\Support\AccountManager\UserManager;
use App\Support\Email\HostedDomains;
use App\Support\Facades\Domain;
use App\User as DatabaseUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Gives a user an organization address and switches what they sign in with to it.
 *
 * When the address is not the one the user already has, their current email moves to be
 * their recovery email (or an existing, working recovery email is kept and the current
 * one is dropped), and `mail` becomes the new address. When it is the same address (one
 * moving to be hosted here from another provider) nothing about `mail` changes and only
 * the mailbox is recorded. Either way a user gets one organization address.
 *
 * A mailbox is `pending` while only offered and `active` once the user accepted it. The
 * switch can be undone for a while afterwards (config/email.php).
 */
class MailboxService
{
    public function __construct(private RecoveryEmailService $recovery_emails, private MailAddressRegistry $registry) {}

    public function forUser(string $username): ?Mailbox
    {
        return Mailbox::where('username', $username)->orderByDesc('id')->first();
    }

    public function active(string $username): ?Mailbox
    {
        return Mailbox::where('username', $username)->where('status', Mailbox::ACTIVE)->first();
    }

    /**
     * Gives the user a mailbox, still pending: nothing about the user changes until they
     * accept it.
     *
     * @throws MailboxException
     */
    public function offer(UserManager $user, OrgDomain $domain, string $local_part, ?string $display_name = null): Mailbox
    {
        $username = (string) $user->attribute('username');

        if ($this->forUser($username)) {
            throw new MailboxException(__('organization.email.mailbox.has_one'));
        }

        $address = Str::lower($local_part.'@'.$domain->name);

        if ($this->registry->inUse($address, $username)) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }

        try {
            return Mailbox::create([
                'organization_id' => $domain->organization_id,
                'org_domain_id' => $domain->id,
                'local_part' => Str::lower($local_part),
                'address' => $address,
                'username' => $username,
                'display_name' => $display_name,
                'status' => Mailbox::PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new MailboxException(__('organization.email.mailbox.taken', ['email' => $address]));
        }
    }

    /**
     * Whether accepting this mailbox changes the user's email, and what has to be given.
     *
     * @return array{changes_email: bool, current: string, recovery: string} recovery is 'keep' (a working one exists), 'move' (the current email becomes it) or 'required' (one must be given)
     */
    public function plan(UserManager $user, Mailbox $mailbox): array
    {
        $username = (string) $user->attribute('username');
        $current = Str::lower(trim((string) $user->attribute('email')));

        if ($current === $mailbox->address) {
            return ['changes_email' => false, 'current' => $current, 'recovery' => 'keep'];
        }

        $existing = $this->recovery_emails->usable($username, $current);

        if ($existing !== null && strcasecmp($existing, $mailbox->address) !== 0) {
            $recovery = 'keep';
        } elseif (! HostedDomains::isHosted($current)) {
            $recovery = 'move';
        } else {
            $recovery = 'required';
        }

        return ['changes_email' => true, 'current' => $current, 'recovery' => $recovery];
    }

    /**
     * The user accepts their mailbox. Writes happen in an order that never loses the old
     * address, and if the directory can't be written everything is put back.
     *
     * @throws MailboxException
     */
    public function accept(UserManager $user, Mailbox $mailbox, ?string $recovery_email = null): Mailbox
    {
        $username = (string) $user->attribute('username');

        if ($mailbox->username !== $username || $mailbox->status !== Mailbox::PENDING) {
            throw new MailboxException(__('organization.email.mailbox.not_pending'));
        }

        // An offer can be made while the domain's email is still being set up, but nothing is
        // written until the domain receives mail here
        if ($mailbox->domain?->emailIsBeingActivated()) {
            throw new MailboxException(__('organization.email.mailbox.domain_not_ready', ['domain' => $mailbox->domain->name]));
        }

        $plan = $this->plan($user, $mailbox);
        $current = $plan['current'];
        $address = $mailbox->address;
        $previous_recovery = $this->recovery_emails->get($username);
        $recovery_after = $previous_recovery;
        $now_recovery = false;

        if ($plan['changes_email']) {
            if ($plan['recovery'] === 'move') {
                $recovery_after = $current;
                $now_recovery = true;
            } elseif ($plan['recovery'] === 'required') {
                $given = $recovery_email === null ? '' : Str::lower(trim($recovery_email));

                if ($given === '' || $given === $address || $given === $current || HostedDomains::isHosted($given)) {
                    throw new RecoveryEmailRequired(__('organization.email.mailbox.recovery_required'));
                }

                $recovery_after = $given;
            }
        }

        try {
            if ($recovery_after !== $previous_recovery) {
                $this->recovery_emails->set($username, $recovery_after, verified: $now_recovery);
            }

            if ($plan['changes_email']) {
                $this->changeEmail($user, $username, $address);
            }

            $this->provision($user, $mailbox);
        } catch (\Throwable $e) {
            report($e);
            $this->putBack($user, $username, $plan['changes_email'] ? $current : null, $previous_recovery);

            throw new MailboxException(__('organization.email.mailbox.failed'), 0, $e);
        }

        $mailbox->forceFill([
            'status' => Mailbox::ACTIVE,
            'activated_at' => now(),
            'swapped_at' => $plan['changes_email'] ? now() : null,
            'previous_mail' => $plan['changes_email'] ? $current : null,
            'previous_recovery_email' => $plan['changes_email'] ? $previous_recovery : null,
        ])->save();

        event(new UserUpdated($user));
        event(new MailboxActivated($mailbox));

        if ($plan['changes_email']) {
            event(new UserEmailChanged($user->organization(), $username, $current, $address));
            $this->tell($current, new PrimaryEmailChanged($username, $current, $address, $now_recovery, $mailbox->swapped_at->copy()->addDays((int) config('email.undo_days', 7))));
        }

        return $mailbox;
    }

    /**
     * Accepts the mailbox for the user when doing so changes nothing about them: it is the
     * address they already have, so there is no switch to consent to. Anything else is left
     * for the user to accept. Nothing happens until the domain receives mail here.
     *
     * @return bool Whether the mailbox is active now
     *
     * @throws MailboxException
     */
    public function acceptIfUnchanged(UserManager $user, Mailbox $mailbox): bool
    {
        if ($mailbox->isActive()) {
            return true;
        }

        if ($mailbox->username !== (string) $user->attribute('username')
            || $mailbox->domain?->emailIsBeingActivated()
            || $this->plan($user, $mailbox)['changes_email']) {
            return false;
        }

        $this->accept($user, $mailbox);

        return true;
    }

    /**
     * Puts the user back on the email they had, for as long as the undo window lasts. The
     * mailbox goes back to pending. Accounts an app has already made for the new address
     * are not touched.
     *
     * @throws MailboxException
     */
    public function undo(UserManager $user, Mailbox $mailbox): Mailbox
    {
        $username = (string) $user->attribute('username');

        if ($mailbox->username !== $username || ! $mailbox->canBeUndone() || ! $mailbox->previous_mail) {
            throw new MailboxException(__('organization.email.mailbox.undo_expired'));
        }

        $switched_to = $mailbox->address;
        $restored = $mailbox->previous_mail;

        try {
            $this->changeEmail($user, $username, $mailbox->previous_mail);

            $this->recovery_emails->set($username, $mailbox->previous_recovery_email);

            $this->deprovision($user, $mailbox);
        } catch (\Throwable $e) {
            report($e);

            throw new MailboxException(__('organization.email.mailbox.failed'), 0, $e);
        }

        $mailbox->forceFill([
            'status' => Mailbox::PENDING,
            'activated_at' => null,
            'swapped_at' => null,
            'previous_mail' => null,
            'previous_recovery_email' => null,
        ])->save();

        event(new UserUpdated($user));
        event(new UserEmailChanged($user->organization(), $username, $switched_to, $restored));
        event(new MailboxDeactivated($mailbox));

        return $mailbox;
    }

    /**
     * Takes the mailbox away. A user who was switched to it goes back to their recovery
     * email as their email, but only if that one is usable; otherwise they stay on the
     * address they have, and are reminded to add a recovery email.
     */
    public function remove(UserManager $user, Mailbox $mailbox): void
    {
        $username = (string) $user->attribute('username');

        if ($mailbox->isActive()) {
            $restore = $mailbox->swapped_at !== null ? $this->recovery_emails->usable($username, $mailbox->address) : null;

            try {
                if ($restore !== null) {
                    $this->changeEmail($user, $username, $restore);
                    $this->recovery_emails->forget($username);
                }

                $this->deprovision($user, $mailbox);
            } catch (\Throwable $e) {
                report($e);

                throw new MailboxException(__('organization.email.mailbox.failed'), 0, $e);
            }

            event(new UserUpdated($user));

            if ($restore !== null) {
                event(new UserEmailChanged($user->organization(), $username, $mailbox->address, $restore));
            }
        }

        $mailbox->delete();
        event(new MailboxDeactivated($mailbox));
    }

    /**
     * Forgets the user's mailboxes when the user is deleted (their directory entry or row
     * goes with them, so there is nothing to restore).
     */
    public function forgetUser(string $username): void
    {
        Mailbox::where('username', $username)->delete();
    }

    private function changeEmail(UserManager $user, string $username, string $address): void
    {
        $user->update(['email' => $address]);
        $user->save();

        // The control panel's own copy of the user (the directory's is re-imported at the next sign in)
        DatabaseUser::where('username', $username)->first()?->forceFill(['email' => $address])->save();
    }

    private function provision(UserManager $user, Mailbox $mailbox): void
    {
        $domain = $mailbox->domain;

        if ($domain) {
            $server = Domain::connect($domain, 'email');

            if (method_exists($server, 'createUserEmail')) {
                $server->createUserEmail($user, $domain);
            }
        }
    }

    private function deprovision(UserManager $user, Mailbox $mailbox): void
    {
        $domain = $mailbox->domain;

        if ($domain) {
            $server = Domain::connect($domain, 'email');

            if (method_exists($server, 'deleteUserEmail')) {
                $server->deleteUserEmail($user, $mailbox->address);
            }
        }
    }

    /**
     * Best effort after a failure part way: the email the user had, and the recovery email they had.
     */
    private function putBack(UserManager $user, string $username, ?string $email, ?string $recovery): void
    {
        try {
            if ($email !== null) {
                $this->changeEmail($user, $username, $email);
            }

            $this->recovery_emails->set($username, $recovery);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function tell(string $address, $notification): void
    {
        try {
            Notification::route('mail', $address)->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
