<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property string $address
 * @property bool $is_primary
 * @property-read Mailbox $mailbox
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
}
