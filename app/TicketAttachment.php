<?php

namespace App;

use App\Services\Tickets\AttachmentTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int|null $ticket_id
 * @property int|null $ticket_message_id
 * @property int $organization_id
 * @property int|null $uploader_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $scan_status
 * @property bool $inline
 * @property Carbon|null $created_at
 * @property-read Ticket|null $ticket
 * @property-read TicketMessage|null $message
 * @property-read User|null $uploader
 */
class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';

    protected $casts = [
        'inline' => 'boolean',
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        // The file goes with the row. Delete models one by one: a bulk query
        // delete does not fire this and would leave the files behind.
        static::deleted(function (TicketAttachment $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    /**
     * The single URL used everywhere, including inside message HTML. It is
     * the same for customers and staff; access is decided when it is requested.
     */
    public static function urlFor(int $id): string
    {
        return '/support/tickets/attachments/'.$id;
    }

    public function url(): string
    {
        return self::urlFor($this->id);
    }

    public function isImage(): bool
    {
        return AttachmentTypes::isRaster($this->mime);
    }

    public function isDraft(): bool
    {
        return $this->ticket_message_id === null;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id')->withTrashed();
    }
}
