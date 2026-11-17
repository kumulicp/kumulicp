<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $org_domain_id
 * @property string $local_part
 * @property string $address
 * @property string|null $username
 * @property string|null $display_name
 * @property string $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $swapped_at
 * @property string|null $previous_mail
 * @property string|null $previous_recovery_email
 * @property-read Organization $organization
 * @property-read OrgDomain|null $domain
 */
class Mailbox extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    protected $table = 'mailboxes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'swapped_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(OrgDomain::class, 'org_domain_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Whether the user can still take the switch back: only a mailbox that changed the
     * user's `mail`, and only for the number of days set in config/email.php.
     */
    public function canBeUndone(): bool
    {
        return $this->isActive()
            && $this->swapped_at !== null
            && $this->swapped_at->copy()->addDays((int) config('email.undo_days', 7))->isFuture();
    }
}
