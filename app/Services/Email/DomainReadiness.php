<?php

namespace App\Services\Email;

use App\Mailbox;
use App\OrgDomain;
use App\Rules\EmailLocalPart;
use App\Support\Facades\AccountManager;
use App\UserRecoveryEmail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * What happens to the people on a domain whose email is being turned on here (one moving over
 * from another provider, say).
 *
 * Switching the domain's DNS moves every address on it at once, so every user whose `mail` is on
 * the domain needs a mailbox here. They get one automatically when the domain goes live: it is
 * the address they already have, so nothing changes for them and there is nothing to accept
 * (switchOver). What is worth doing beforehand is giving each of them a secondary recovery
 * email, because their sign-in address stops being somewhere a password reset can reach them,
 * so before the domain is enabled and while it is being set up this reports who is on it and
 * who has one. Anyone whose recovery email is on the domain is flagged too, because it stops
 * being a place to receive a reset the moment the mail moves. The old mail itself is not copied.
 */
class DomainReadiness
{
    private const LIST_LIMIT = 50;

    public function __construct(private MailboxService $mailboxes, private RecoveryEmailService $recovery_emails) {}

    /**
     * @return array{ready: bool, switching_count: int, with_secondary_count: int, without_secondary: array<int, array<string, string>>, without_secondary_count: int, already_count: int, cannot_switch: array<int, array<string, string>>, cannot_switch_count: int, recovery_on_domain: array<int, array<string, string>>, recovery_on_domain_count: int, offers_waiting: int}
     */
    public function for(OrgDomain $domain): array
    {
        $suffix = '@'.Str::lower($domain->name);
        $people = $this->people($domain);

        $switching = $people->where('state', 'switching');
        $without_secondary = $switching->where('has_secondary', false);
        $cannot_switch = $people->whereIn('state', ['not_standard', 'name_not_allowed', 'other_offer']);

        $names = $people->mapWithKeys(fn ($person) => [$person['username'] => $person['name']]);
        $recovery = UserRecoveryEmail::whereRaw('lower(email) like ?', ['%'.$suffix])->orderBy('username')->get()
            ->map(fn ($row) => ['username' => $row->username, 'name' => $names[$row->username] ?? $row->username, 'email' => $row->email])
            ->values();

        return [
            'ready' => $cannot_switch->isEmpty(),
            'switching_count' => $switching->count(),
            'with_secondary_count' => $switching->count() - $without_secondary->count(),
            'without_secondary' => $this->listed($without_secondary),
            'without_secondary_count' => $without_secondary->count(),
            'already_count' => $people->where('state', 'active')->count(),
            'cannot_switch' => $this->listed($cannot_switch, ['reason' => 'state']),
            'cannot_switch_count' => $cannot_switch->count(),
            'recovery_on_domain' => $recovery->take(self::LIST_LIMIT)->all(),
            'recovery_on_domain_count' => $recovery->count(),
            'offers_waiting' => Mailbox::where('org_domain_id', $domain->id)->where('status', Mailbox::PENDING)->count(),
        ];
    }

    /**
     * Gives every user whose `mail` is on the domain the mailbox for the address they already
     * have, and accepts it for them: it changes nothing about them, so it has no risk to
     * consent to. Run when the domain goes live. One that can't be done (the mail server
     * refused, say) stays an offer the person can accept from their profile.
     *
     * @return array{switched: int, failed: array<int, string>}
     */
    public function switchOver(OrgDomain $domain): array
    {
        $switched = 0;
        $failed = [];

        foreach ($this->people($domain)->where('state', 'switching') as $person) {
            try {
                $mailbox = $person['mailbox'] ?? $this->mailboxes->offer($person['user'], $domain, Str::before($person['email'], '@'), $person['name']);

                if ($this->mailboxes->acceptIfUnchanged($person['user'], $mailbox)) {
                    $switched++;
                } else {
                    $failed[] = $person['username'];
                }
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $person['username'];
            }
        }

        return ['switched' => $switched, 'failed' => $failed];
    }

    /**
     * Everyone whose `mail` is on the domain, and what happens to them:
     * `active` (already has the mailbox), `switching` (will get it), or one of the reasons they
     * can't: `not_standard` (only some users can have a mailbox), `name_not_allowed` (the name
     * in front of the @ can't be a mailbox's) and `other_offer` (they were offered a different
     * address, which they have to accept themselves).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function people(OrgDomain $domain): Collection
    {
        $suffix = '@'.Str::lower($domain->name);

        $on_domain = AccountManager::users($domain->organization)->collect()
            ->filter(fn ($user) => Str::endsWith(Str::lower((string) $user->attribute('email')), $suffix))
            ->values();

        $mailboxes = Mailbox::whereIn('username', $on_domain->map(fn ($user) => (string) $user->attribute('username'))->all())
            ->orderBy('id')
            ->get()
            ->keyBy('username');

        return $on_domain->map(function ($user) use ($mailboxes, $suffix) {
            $username = (string) $user->attribute('username');
            $email = (string) $user->attribute('email');
            $mailbox = $mailboxes->get($username);

            if ($mailbox?->isActive()) {
                $state = 'active';
            } elseif ($mailbox && strcasecmp($mailbox->address, $email) !== 0) {
                $state = 'other_offer';
            } elseif (! $user->isUserAccessType('standard')) {
                $state = 'not_standard';
            } elseif (! $this->localPartAllowed($email)) {
                $state = 'name_not_allowed';
            } else {
                $state = 'switching';
            }

            // A secondary recovery email on this very domain moves with the mail, so it doesn't count
            $secondary = $this->recovery_emails->usable($username, $email);

            return [
                'username' => $username,
                'name' => (string) $user->attribute('name'),
                'email' => $email,
                'state' => $state,
                'has_secondary' => $secondary !== null && ! Str::endsWith(Str::lower($secondary), $suffix),
                'mailbox' => $state === 'switching' ? $mailbox : null,
                'user' => $user,
            ];
        });
    }

    /**
     * @param  array<string, string>  $extra  Extra fields by name, taken from the person's entry
     * @return array<int, array<string, string>>
     */
    private function listed(Collection $people, array $extra = []): array
    {
        return $people->take(self::LIST_LIMIT)
            ->map(fn ($person) => collect(['username', 'name', 'email'])
                ->mapWithKeys(fn ($key) => [$key => $person[$key]])
                ->merge(collect($extra)->map(fn ($key) => $person[$key]))
                ->all())
            ->values()
            ->all();
    }

    private function localPartAllowed(string $email): bool
    {
        $local_part = Str::lower(Str::before($email, '@'));

        return Validator::make(['local_part' => $local_part], ['local_part' => [new EmailLocalPart]])->passes();
    }
}
