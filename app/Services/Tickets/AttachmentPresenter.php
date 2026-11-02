<?php

namespace App\Services\Tickets;

use App\TicketAttachment;
use Illuminate\Support\Collection;

/**
 * How an attachment is described to the browser. Never includes the storage
 * path or the uploader.
 */
class AttachmentPresenter
{
    /**
     * @return array{id: int, name: string, size: int, mime: string, url: string, is_image: bool, inline: bool}
     */
    public static function one(TicketAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'size' => $attachment->size,
            'mime' => $attachment->mime,
            'url' => $attachment->url(),
            'is_image' => $attachment->isImage(),
            'inline' => $attachment->inline,
        ];
    }

    /**
     * @param  iterable<TicketAttachment>  $attachments
     * @return list<array<string, mixed>>
     */
    public static function collection(iterable $attachments): array
    {
        return Collection::make($attachments)->map(fn (TicketAttachment $attachment) => self::one($attachment))->values()->all();
    }
}
