<?php

namespace App\Services\Email;

use App\EmailForwarder;
use App\EmailForwarderDestination;
use App\Events\Mailboxes\ForwarderChanged;
use App\Events\Mailboxes\ForwarderRemoved;
use App\Mailbox;
use App\MailboxAddress;
use App\Notifications\ForwarderDestinationConfirmation;
use App\Organization;
use App\OrgDomain;
use App\Rules\EmailLocalPart;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Forwarders: an address that delivers to one or more destinations. The database is the source
 * of truth and the mail server plugin applies it from `ForwarderChanged` and `ForwarderRemoved`.
 *
 * A destination inside the organization has to exist (a mailbox in use, an alias or another
 * forwarder) and no chain of forwarders may lead back to where it started. A destination
 * outside the organization is held until its owner opens a link sent to it, so a forwarder can't
 * be used to flood someone or to read their mail.
 */
class ForwarderService
{
    /** How many forwarders deep a chain is followed before it is refused */
    private const MAX_DEPTH = 5;

    public function __construct(private MailAddressRegistry $registry) {}

    /**
     * Makes a forwarder with its first destination.
     *
     * @throws ForwarderException
     */
    public function create(Organization $organization, OrgDomain $domain, string $local_part, string $destination): EmailForwarder
    {
        if ((int) $domain->organization_id !== (int) $organization->id || ! $domain->email_enabled) {
            throw new ForwarderException(__('organization.email.forwarder.domain_not_allowed', ['domain' => $domain->name]));
        }

        $local_part = Str::lower(trim($local_part));

        if (Validator::make(['local_part' => $local_part], ['local_part' => [new EmailLocalPart]])->fails()) {
            throw new ForwarderException(__('messages.rule.email_local_part'));
        }

        $max = (int) $organization->plan?->setting('forwarder.max');

        if ($max > 0 && EmailForwarder::where('organization_id', $organization->id)->count() >= $max) {
            throw new ForwarderException(__('organization.email.forwarder.plan_limit', ['count' => $max]));
        }

        $address = $local_part.'@'.Str::lower($domain->name);

        if ($this->registry->inUse($address)) {
            throw new ForwarderException(__('organization.email.mailbox.taken', ['email' => $address]));
        }

        // Checked against an address that does not exist yet, so only the first destination's own rules apply
        $destination = $this->normalize($destination);
        $kind = $this->classify($organization, $destination, $address);

        try {
            return DB::transaction(function () use ($organization, $domain, $address, $destination, $kind) {
                $forwarder = EmailForwarder::create([
                    'organization_id' => $organization->id,
                    'domain_id' => $domain->id,
                    'email' => $address,
                ]);

                $this->attach($forwarder, $destination, $kind);

                event(new ForwarderChanged($forwarder));

                return $forwarder;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ForwarderException(__('organization.email.mailbox.taken', ['email' => $address]));
        }
    }

    /**
     * @throws ForwarderException
     */
    public function addDestination(EmailForwarder $forwarder, string $destination): EmailForwarderDestination
    {
        $destination = $this->normalize($destination);

        if ($forwarder->destinations()->count() >= (int) config('email.max_forwarder_destinations', 10)) {
            throw new ForwarderException(__('organization.email.forwarder.destination_limit', ['count' => (int) config('email.max_forwarder_destinations', 10)]));
        }

        if ($forwarder->destinations()->where('destination', $destination)->exists()) {
            throw new ForwarderException(__('organization.email.forwarder.duplicate', ['email' => $destination]));
        }

        $organization = Organization::findOrFail($forwarder->organization_id);
        $kind = $this->classify($organization, $destination, $forwarder->email, $forwarder);

        $row = $this->attach($forwarder, $destination, $kind);

        $this->changed($forwarder);

        return $row;
    }

    /**
     * @throws ForwarderException
     */
    public function removeDestination(EmailForwarder $forwarder, string $destination): void
    {
        $row = $forwarder->destinations()->where('destination', Str::lower(trim($destination)))->first();

        if (! $row) {
            throw new ForwarderException(__('organization.email.forwarder.destination_not_found', ['email' => $destination]));
        }

        if ($forwarder->destinations()->count() <= 1) {
            throw new ForwarderException(__('organization.email.forwarder.last_destination'));
        }

        $row->delete();

        $this->changed($forwarder);
    }

    public function remove(EmailForwarder $forwarder): void
    {
        $removed = new ForwarderRemoved($forwarder->organization_id, $forwarder->domain_id, $forwarder->email);

        $forwarder->delete();

        event($removed);
    }

    /**
     * Sends the confirmation link again, with a new token (the old link stops working).
     *
     * @throws ForwarderException
     */
    public function resend(EmailForwarder $forwarder, string $destination): void
    {
        $row = $forwarder->destinations()->where('destination', Str::lower(trim($destination)))->first();

        if (! $row || $row->kind !== EmailForwarderDestination::EXTERNAL || $row->confirmed_at !== null) {
            throw new ForwarderException(__('organization.email.forwarder.nothing_to_confirm', ['email' => $destination]));
        }

        $this->sendConfirmation($forwarder, $row);
    }

    /**
     * The owner of an outside address opens the link: forwarding to it starts.
     */
    public function confirm(int $destination_id, string $token): bool
    {
        $row = EmailForwarderDestination::with('forwarder')->find($destination_id);

        if (! $row || $row->confirmed_at !== null || ! $row->token_hash || ! hash_equals($row->token_hash, hash('sha256', $token))) {
            return false;
        }

        $expires = $row->confirmation_sent_at?->copy()->addHours((int) config('email.forwarder_confirm_hours', 72));

        if (! $expires || $expires->isPast()) {
            return false;
        }

        $row->forceFill(['confirmed_at' => now(), 'token_hash' => null])->save();

        $this->changed($row->forwarder);

        return true;
    }

    /**
     * Records what the mail server did with the forwarder as it is now.
     */
    public function applied(EmailForwarder $forwarder, bool $succeeded, ?string $error = null): void
    {
        $forwarder->forceFill([
            'status' => $succeeded ? EmailForwarder::ACTIVE : EmailForwarder::FAILED,
            'applied_at' => $succeeded ? now() : $forwarder->applied_at,
            'last_error' => $succeeded ? null : $error,
        ])->save();
    }

    private function changed(EmailForwarder $forwarder): void
    {
        $forwarder->forceFill(['status' => EmailForwarder::PENDING, 'last_error' => null])->save();

        event(new ForwarderChanged($forwarder));
    }

    private function attach(EmailForwarder $forwarder, string $destination, string $kind): EmailForwarderDestination
    {
        $row = $forwarder->destinations()->create([
            'destination' => $destination,
            'kind' => $kind,
            'confirmed_at' => $kind === EmailForwarderDestination::INTERNAL ? now() : null,
        ]);

        if ($kind === EmailForwarderDestination::EXTERNAL) {
            $this->sendConfirmation($forwarder, $row);
        }

        return $row;
    }

    private function sendConfirmation(EmailForwarder $forwarder, EmailForwarderDestination $row): void
    {
        $token = Str::random(40);

        $row->forceFill(['token_hash' => hash('sha256', $token), 'confirmation_sent_at' => now()])->save();

        Notification::route('mail', $row->destination)->notify(new ForwarderDestinationConfirmation(
            $forwarder->email,
            URL::temporarySignedRoute(
                'forwarder.destination.confirm',
                now()->addHours((int) config('email.forwarder_confirm_hours', 72)),
                ['destination' => $row->id, 'token' => $token],
            ),
        ));
    }

    /**
     * @throws ForwarderException
     */
    private function normalize(string $destination): string
    {
        $destination = Str::lower(trim($destination));

        if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            throw new ForwarderException(__('organization.email.forwarder.invalid', ['email' => $destination]));
        }

        return $destination;
    }

    /**
     * Whether a destination is inside the organization (and then it has to exist and not loop
     * back) or outside it (and then it is confirmed by its owner).
     *
     * @throws ForwarderException
     */
    private function classify(Organization $organization, string $destination, string $own_address, ?EmailForwarder $forwarder = null): string
    {
        if ($destination === Str::lower($own_address)) {
            throw new ForwarderException(__('organization.email.forwarder.itself'));
        }

        $domain = Str::after($destination, '@');
        $own = $organization->domains()->whereRaw('lower(name) = ?', [$domain])->exists();

        if (! $own) {
            return EmailForwarderDestination::EXTERNAL;
        }

        $target = MailboxAddress::where('address', $destination)->first();

        if (! $target || ! $this->isLiveTarget($target, $organization)) {
            throw new ForwarderException(__('organization.email.forwarder.internal_missing', ['email' => $destination]));
        }

        if ($target->forwarder_id !== null) {
            $this->refuseLoops($target->forwarder, $own_address, $forwarder, 1);
        }

        return EmailForwarderDestination::INTERNAL;
    }

    /**
     * An address of the organization that mail can be delivered to: a mailbox in use (or one of
     * its aliases) or another forwarder.
     */
    private function isLiveTarget(MailboxAddress $target, Organization $organization): bool
    {
        if ($target->forwarder_id !== null) {
            return (int) $target->forwarder?->organization_id === (int) $organization->id;
        }

        $mailbox = $target->mailbox;

        return $mailbox !== null
            && (int) $mailbox->organization_id === (int) $organization->id
            && $mailbox->status === Mailbox::ACTIVE;
    }

    /**
     * Follows the forwarders the new destination leads to, and refuses if one of them leads back
     * to the forwarder being made or changed, or the chain is too long to follow.
     *
     * @throws ForwarderException
     */
    private function refuseLoops(EmailForwarder $next, string $own_address, ?EmailForwarder $forwarder, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ForwarderException(__('organization.email.forwarder.too_deep', ['count' => self::MAX_DEPTH]));
        }

        if (($forwarder && $next->is($forwarder)) || Str::lower($next->email) === Str::lower($own_address)) {
            throw new ForwarderException(__('organization.email.forwarder.loop'));
        }

        foreach ($next->destinations()->where('kind', EmailForwarderDestination::INTERNAL)->get() as $destination) {
            $onward = MailboxAddress::where('address', $destination->destination)->first();

            if ($onward?->forwarder_id !== null) {
                $this->refuseLoops($onward->forwarder, $own_address, $forwarder, $depth + 1);
            }
        }
    }
}
