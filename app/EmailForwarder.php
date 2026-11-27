<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An address that delivers to one or more destinations, inside or outside the organization.
 * Unlike an alias it is not another name for a mailbox, and it does not have to be one itself.
 *
 * The address is a row of `mailbox_addresses`, which is what keeps it from being given to
 * anyone else. The panel's database is the source of truth; the mail server applies it, and
 * `status` says how far that got.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $domain_id
 * @property string $email
 * @property string $status
 * @property Carbon|null $applied_at
 * @property string|null $last_error
 * @property-read OrgDomain $domain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EmailForwarderDestination> $destinations
 */
class EmailForwarder extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    protected $table = 'email_forwarders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime'];
    }

    /**
     * The address is kept in lower case, and its `mailbox_addresses` row follows it. If that row
     * can't be made (the address is taken) the forwarder is taken back out, so a failed create
     * leaves nothing behind. Deleting the forwarder removes the row and its destinations.
     */
    protected static function booted(): void
    {
        static::saving(function (EmailForwarder $forwarder) {
            $forwarder->email = Str::lower(trim($forwarder->email));
        });

        static::created(function (EmailForwarder $forwarder) {
            try {
                MailboxAddress::create(['forwarder_id' => $forwarder->id, 'address' => $forwarder->email, 'is_primary' => true]);
            } catch (\Throwable $e) {
                $forwarder->delete();

                throw $e;
            }
        });

        static::updated(function (EmailForwarder $forwarder) {
            if ($forwarder->wasChanged('email')) {
                $forwarder->address()->update(['address' => $forwarder->email]);
            }
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(OrgDomain::class, 'domain_id');
    }

    /**
     * @return HasMany<EmailForwarderDestination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(EmailForwarderDestination::class);
    }

    /**
     * The destinations mail is delivered to now: the organization's own, and the outside ones
     * whose owner has confirmed them. What the mail server applies.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EmailForwarderDestination>
     */
    public function liveDestinations()
    {
        return $this->destinations()->get()->filter(fn (EmailForwarderDestination $destination) => $destination->isLive())->values();
    }

    public function address(): HasOne
    {
        return $this->hasOne(MailboxAddress::class, 'forwarder_id');
    }
}
