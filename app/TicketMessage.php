<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $author_id
 * @property string $author_kind
 * @property string $visibility
 * @property string $body_html
 * @property string $body_text
 * @property string $source
 * @property Carbon|null $edited_at
 * @property Carbon|null $redacted_at
 * @property Carbon|null $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $author
 */
class TicketMessage extends Model
{
    use SoftDeletes;

    public const PUBLIC = 'public';

    public const INTERNAL = 'internal';

    public const KIND_REQUESTER = 'requester';

    public const KIND_STAFF = 'staff';

    public const KIND_SYSTEM = 'system';

    protected $table = 'ticket_messages';

    protected $casts = [
        'edited_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    public function isInternal(): bool
    {
        return $this->visibility === self::INTERNAL;
    }

    public function isStaff(): bool
    {
        return $this->author_kind === self::KIND_STAFF;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }

    /**
     * @return HasMany<TicketMessageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(TicketMessageRevision::class)->orderBy('id');
    }
}
