<?php

namespace App\Services\Tickets;

use App\Contracts\Tickets\AttachmentScanner;
use App\Ticket;
use App\TicketAttachment;
use App\TicketMessage;
use App\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uploads are a two step affair. A file is uploaded on its own and becomes a
 * "draft" owned by the uploader; sending a message with `attachment_ids`
 * then binds those drafts to it. Nothing is lost when a form fails validation,
 * and pasted screenshots can be uploaded before the ticket exists.
 */
class AttachmentService
{
    public function __construct(private AttachmentTypes $types) {}

    private function disk(): string
    {
        return config('tickets.attachments.disk');
    }

    /**
     * Accept one uploaded file as a draft owned by $uploader.
     *
     * @throws ValidationException
     */
    public function store(UploadedFile $file, User $uploader): TicketAttachment
    {
        if (! $file->isValid()) {
            throw $this->invalid('file', __('tickets.attachments.upload_failed', ['reason' => $file->getErrorMessage()]));
        }

        $size = (int) $file->getSize();
        $max_kb = config('tickets.attachments.max_file_kb');

        if ($size <= 0) {
            throw $this->invalid('file', __('tickets.attachments.empty'));
        }

        if ($size > $max_kb * 1024) {
            throw $this->invalid('file', __('tickets.attachments.too_large', ['max' => $this->megabytes($max_kb)]));
        }

        $drafts = TicketAttachment::where('uploader_id', $uploader->id)->whereNull('ticket_message_id')->count();
        if ($drafts >= config('tickets.attachments.max_drafts_per_user')) {
            throw $this->invalid('file', __('tickets.attachments.too_many_waiting'));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $this->types->detect($file->getRealPath());

        if (! $this->types::accepts($extension, $mime)) {
            throw $this->invalid('file', __('tickets.attachments.type_not_allowed', ['types' => implode(', ', $this->types::extensions())]));
        }

        if (($scanner = $this->scanner()) && ! $scanner->isClean($file->getRealPath())) {
            throw $this->invalid('file', __('tickets.attachments.rejected'));
        }

        $disk = $this->disk();
        $path = Storage::disk($disk)->putFileAs(
            $uploader->organization_id.'/'.Str::substr((string) Str::uuid(), 0, 2),
            $file,
            (string) Str::uuid(),
        );

        if (! is_string($path)) {
            throw $this->invalid('file', __('tickets.attachments.upload_failed', ['reason' => __('tickets.attachments.not_saved')]));
        }

        $attachment = new TicketAttachment;
        $attachment->organization_id = $uploader->organization_id;
        $attachment->uploader_id = $uploader->id;
        $attachment->disk = $disk;
        $attachment->path = $path;
        $attachment->original_name = $this->displayName($file->getClientOriginalName(), $extension);
        $attachment->mime = $mime;
        $attachment->size = $size;
        $attachment->sha256 = (string) hash_file('sha256', $file->getRealPath());
        $attachment->scan_status = $this->scanner() ? 'clean' : 'skipped';
        $attachment->save();

        return $attachment;
    }

    /**
     * The uploader's own drafts for the given ids, checked against the limits.
     * Nothing is changed; call bind() inside the transaction that creates the message.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, TicketAttachment>
     *
     * @throws ValidationException
     */
    public function drafts(array $ids, User $uploader): Collection
    {
        $ids = collect($ids)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        if ($ids->count() > config('tickets.attachments.max_files')) {
            throw $this->invalid('attachment_ids', __('tickets.attachments.too_many', ['max' => config('tickets.attachments.max_files')]));
        }

        $drafts = TicketAttachment::whereIn('id', $ids)
            ->where('uploader_id', $uploader->id)
            ->whereNull('ticket_message_id')
            ->get();

        // Somebody else's upload, one already sent, or one that expired
        if ($drafts->count() !== $ids->count()) {
            throw $this->invalid('attachment_ids', __('tickets.attachments.unavailable'));
        }

        $max_kb = config('tickets.attachments.max_message_kb');
        if ($drafts->sum('size') > $max_kb * 1024) {
            throw $this->invalid('attachment_ids', __('tickets.attachments.message_too_large', ['max' => $this->megabytes($max_kb)]));
        }

        return $drafts;
    }

    /**
     * Attach the drafts to a message. Which ones are shown inside the text is
     * worked out here from the sanitized HTML, not taken from the browser.
     *
     * @param  Collection<int, TicketAttachment>  $drafts
     * @param  list<int>  $inlineIds  Ids that the cleaned message HTML shows as images
     */
    public function bind(Collection $drafts, Ticket $ticket, TicketMessage $message, array $inlineIds = []): void
    {
        foreach ($drafts as $draft) {
            // The row may have been claimed by a concurrent request since drafts() read it
            $claimed = TicketAttachment::where('id', $draft->id)
                ->whereNull('ticket_message_id')
                ->update([
                    'ticket_id' => $ticket->id,
                    'ticket_message_id' => $message->id,
                    'inline' => in_array($draft->id, $inlineIds, true),
                ]);

            if ($claimed !== 1) {
                throw $this->invalid('attachment_ids', __('tickets.attachments.unavailable'));
            }
        }
    }

    /**
     * Remove an upload that was never sent.
     */
    public function discardDraft(TicketAttachment $attachment, User $user): bool
    {
        if (! $attachment->isDraft() || $attachment->uploader_id !== $user->id) {
            return false;
        }

        $attachment->delete();

        return true;
    }

    /**
     * Whether $user may open this file. Drafts are private to the uploader.
     * Sent files follow the message: customers of the ticket's organization
     * see files on public messages, staff see everything.
     */
    public function canView(TicketAttachment $attachment, User $user): bool
    {
        if ($attachment->isDraft()) {
            return $attachment->uploader_id === $user->id;
        }

        $ticket = $attachment->ticket;

        if ($ticket && $ticket->organization_id === $user->organization_id && $attachment->message && ! $attachment->message->isInternal()) {
            return true;
        }

        return Gate::forUser($user)->allows('manage', Ticket::class);
    }

    /**
     * Stream the file. Only raster images are ever shown in the browser;
     * everything else downloads. `nosniff` and a locked-down CSP stop the
     * browser guessing that a file is something it can run.
     */
    public function response(TicketAttachment $attachment, bool $forceDownload = false): Response
    {
        $disposition = $forceDownload || ! $attachment->isImage() ? 'attachment' : 'inline';

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ], $disposition);
    }

    /**
     * Delete uploads that were never sent with a message.
     *
     * @return int number deleted
     */
    public function pruneOrphans(): int
    {
        $count = 0;

        TicketAttachment::whereNull('ticket_message_id')
            ->where('created_at', '<', now()->subHours(config('tickets.attachments.orphan_hours')))
            ->each(function (TicketAttachment $attachment) use (&$count) {
                $attachment->delete();
                $count++;
            });

        return $count;
    }

    private function scanner(): ?AttachmentScanner
    {
        $class = config('tickets.attachments.scanner');

        return $class ? app($class) : null;
    }

    /**
     * The name shown to people: no folders, no control characters, bounded length.
     */
    private function displayName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment'.($extension !== '' ? '.'.$extension : '');
        }

        return Str::limit($name, 150, '');
    }

    private function megabytes(int $kb): string
    {
        return rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.');
    }

    private function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
