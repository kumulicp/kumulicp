<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to an organization's admins once it is gone. It only carries plain
 * values: by the time it is sent, the organization and its users no longer exist.
 * It is sent straight away, not queued, so a failure can be handled where it happens.
 */
class OrganizationDeleted extends Mailable
{
    public function __construct(
        public string $organization_name,
        public ?string $reason = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('messages.notification.organization_deleted'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.organization.deleted');
    }
}
