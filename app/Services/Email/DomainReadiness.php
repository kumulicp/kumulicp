<?php

namespace App\Services\Email;

use App\Mailbox;
use App\OrgDomain;
use App\Rules\EmailLocalPart;
use App\Support\Facades\AccountManager;
use App\UserRecoveryEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Whether a domain whose email is being set up (one moving over from another provider, say) is
 * safe to point at this server.
 *
 * Switching the domain's DNS moves every address on it at once, so before that every user whose
 * `mail` is on the domain needs a mailbox here (an offer is enough: it can't be accepted until
 * the domain is live, and accepting the address a user already has changes nothing for them).
 * Anyone whose recovery email is on the domain is flagged too, because it stops being a place
 * to receive a reset the moment the mail moves. The old mail itself is not copied.
 */
class DomainReadiness
{
    private const LIST_LIMIT = 50;

    public function __construct(private MailboxService $mailboxes) {}

    /**
     * @return array{ready: bool, without_mailbox: array<int, array<string, mixed>>, without_mailbox_count: int, recovery_on_domain: array<int, array<string, string>>, recovery_on_domain_count: int, offers_waiting: int}
     */
    public function for(OrgDomain $domain): array
    {
        $suffix = '@'.Str::lower($domain->name);
        $users = AccountManager::users()->collect();

        $on_domain = $users->filter(fn ($user) => Str::endsWith(Str::lower((string) $user->attribute('email')), $suffix));
        $has_mailbox = Mailbox::whereIn('username', $on_domain->map(fn ($user) => (string) $user->attribute('username'))->all())
            ->pluck('username')
            ->all();

        $without = $on_domain
            ->reject(fn ($user) => in_array((string) $user->attribute('username'), $has_mailbox, true))
            ->map(fn ($user) => [
                'username' => (string) $user->attribute('username'),
                'name' => (string) $user->attribute('name'),
                'email' => (string) $user->attribute('email'),
                // Only some users can be given a mailbox, and the name in front of the @ has to be allowed in one
                'can_offer' => $user->isUserAccessType('standard') && $this->localPartAllowed($user),
            ])
            ->values();

        $names = $users->mapWithKeys(fn ($user) => [(string) $user->attribute('username') => (string) $user->attribute('name')]);
        $recovery = UserRecoveryEmail::whereRaw('lower(email) like ?', ['%'.$suffix])->orderBy('username')->get()
            ->map(fn ($row) => ['username' => $row->username, 'name' => $names[$row->username] ?? $row->username, 'email' => $row->email])
            ->values();

        return [
            'ready' => $without->isEmpty(),
            'without_mailbox' => $without->take(self::LIST_LIMIT)->all(),
            'without_mailbox_count' => $without->count(),
            'recovery_on_domain' => $recovery->take(self::LIST_LIMIT)->all(),
            'recovery_on_domain_count' => $recovery->count(),
            'offers_waiting' => Mailbox::where('org_domain_id', $domain->id)->where('status', Mailbox::PENDING)->count(),
        ];
    }

    /**
     * Offers every user whose `mail` is on the domain the address they already have. Nothing
     * changes for them until the domain is live and they accept.
     *
     * @return array{offered: int, skipped: array<int, string>}
     */
    public function offerExisting(OrgDomain $domain): array
    {
        $offered = 0;
        $skipped = [];
        $suffix = '@'.Str::lower($domain->name);

        $users = AccountManager::users()->collect()
            ->filter(fn ($user) => Str::endsWith(Str::lower((string) $user->attribute('email')), $suffix));

        foreach ($users as $user) {
            $username = (string) $user->attribute('username');

            if ($this->mailboxes->forUser($username)) {
                continue;
            }

            if (! $user->isUserAccessType('standard') || ! $this->localPartAllowed($user)) {
                $skipped[] = $username;

                continue;
            }

            try {
                $this->mailboxes->offer($user, $domain, Str::before((string) $user->attribute('email'), '@'), (string) $user->attribute('name'));
                $offered++;
            } catch (MailboxException) {
                $skipped[] = $username;
            }
        }

        return ['offered' => $offered, 'skipped' => $skipped];
    }

    private function localPartAllowed($user): bool
    {
        $local_part = Str::lower(Str::before((string) $user->attribute('email'), '@'));

        return Validator::make(['local_part' => $local_part], ['local_part' => [new EmailLocalPart]])->passes();
    }
}
