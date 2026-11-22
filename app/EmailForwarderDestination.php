<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One place a forwarder delivers to. An `external` destination (outside the organization) only
 * receives mail once its owner has opened the confirmation link; an `internal` one is an address
 * of the organization and needs none.
 *
 * @property int $id
 * @property int $email_forwarder_id
 * @property string $destination
 * @property string $kind
 * @property Carbon|null $confirmed_at
 * @property string|null $token_hash
 * @property Carbon|null $confirmation_sent_at
 * @property-read EmailForwarder $forwarder
 */
class EmailForwarderDestination extends Model
{
    public const INTERNAL = 'internal';

    public const EXTERNAL = 'external';

    protected $table = 'email_forwarder_destinations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'confirmation_sent_at' => 'datetime'];
    }

    /**
     * Lower case, so the same address can't be added twice in another case.
     */
    public function setDestinationAttribute(string $value): void
    {
        $this->attributes['destination'] = Str::lower(trim($value));
    }

    /**
     * Whether mail is delivered to it: an internal destination always, an external one once confirmed.
     */
    public function isLive(): bool
    {
        return $this->kind === self::INTERNAL || $this->confirmed_at !== null;
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(EmailForwarder::class, 'email_forwarder_id');
    }
}
