<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $mailbox_id
 * @property int|null $forwarder_id
 * @property string $address
 * @property bool $is_primary
 * @property-read Mailbox|null $mailbox
 * @property-read EmailForwarder|null $forwarder
 */
class MailboxAddress extends Model
{
    protected $table = 'mailbox_addresses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * An address belongs to a mailbox (its own or an alias) or to a forwarder, never to both and
     * never to neither.
     */
    protected static function booted(): void
    {
        static::saving(function (MailboxAddress $address) {
            if (($address->mailbox_id === null) === ($address->forwarder_id === null)) {
                throw new \InvalidArgumentException('An address belongs to a mailbox or to a forwarder, and to only one of them.');
            }
        });
    }

    /**
     * Stored in lower case, so the unique index treats ME@x.com and me@x.com as one address.
     */
    public function setAddressAttribute(string $value): void
    {
        $this->attributes['address'] = Str::lower(trim($value));
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(EmailForwarder::class, 'forwarder_id');
    }
}
