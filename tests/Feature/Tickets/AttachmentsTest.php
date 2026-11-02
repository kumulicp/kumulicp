<?php

namespace Tests\Feature\Tickets;

use App\Contracts\Tickets\AttachmentScanner;
use App\Services\Tickets\AttachmentService;
use App\Ticket;
use App\TicketAttachment;
use App\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A scanner that rejects everything, to prove the hook is used.
 */
class RejectEverythingScanner implements AttachmentScanner
{
    public function isClean(string $path): bool
    {
        return false;
    }
}

class AttachmentsTest extends TicketsTestCase
{
    /** A valid 1x1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** A tiny real zip archive holding one file */
    private const ZIP = 'UEsDBBQAAAAAAOo5Yl2DFtyMAQAAAAEAAAAFAAAAYS50eHR4UEsBAhQDFAAAAAAA6jliXYMW3IwBAAAAAQAAAAUAAAAAAAAAAAAAAIABAAAAAGEudHh0UEsFBgAAAAABAAEAMwAAACQAAAAAAA==';

    private function png(): string
    {
        return base64_decode(self::PNG);
    }

    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(User $user, string $name = 'screenshot.png', ?string $content = null)
    {
        return $this->actingAs($user)->postJson('/support/tickets/uploads', ['file' => $this->file($name, $content ?? $this->png())]);
    }

    private function draft(User $user, string $name = 'screenshot.png', ?string $content = null): TicketAttachment
    {
        $id = $this->upload($user, $name, $content)->assertCreated()->json('id');

        return TicketAttachment::findOrFail($id);
    }

    private function files(): array
    {
        return Storage::disk('tickets')->allFiles();
    }

    public function test_an_upload_becomes_a_private_draft(): void
    {
        $response = $this->upload($this->alice)->assertCreated()
            ->assertJsonStructure(['id', 'name', 'size', 'mime', 'url', 'is_image', 'inline'])
            ->assertJsonPath('name', 'screenshot.png')
            ->assertJsonPath('mime', 'image/png')
            ->assertJsonPath('is_image', true);

        $attachment = TicketAttachment::findOrFail($response->json('id'));
        $this->assertNull($attachment->ticket_id);
        $this->assertNull($attachment->ticket_message_id);
        $this->assertSame($this->alice->id, $attachment->uploader_id);
        $this->assertSame($this->orgA->id, $attachment->organization_id);
        $this->assertSame(hash('sha256', $this->png()), $attachment->sha256);
        Storage::disk('tickets')->assertExists($attachment->path);
        // The stored name is random and the browser is never told where it is
        $this->assertStringNotContainsString('screenshot', $attachment->path);
        $this->assertStringNotContainsString($attachment->path, $response->getContent());
    }

    public function test_uploads_need_the_feature_and_a_signed_in_user(): void
    {
        $this->post('/support/tickets/uploads', ['file' => $this->file('a.png', $this->png())])->assertRedirect();

        config(['toggle.flags.tickets' => false]);
        $this->upload($this->alice)->assertNotFound();
    }

    public function test_dangerous_and_disguised_files_are_refused(): void
    {
        $cases = [
            'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
            'html' => ['page.html', '<html><script>alert(1)</script></html>'],
            'html named png' => ['photo.png', '<html><script>alert(1)</script></html>'],
            'php named png' => ['photo.png', '<?php system($_GET["c"]); ?>'],
            'exe named png' => ['photo.png', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 200)],
            'pdf named png' => ['photo.png', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
            'script' => ['run.sh', "#!/bin/sh\nrm -rf /\n"],
            'no extension' => ['README', 'hello'],
        ];

        foreach ($cases as $label => [$name, $content]) {
            $this->upload($this->alice, $name, $content)->assertUnprocessable()->assertJsonValidationErrors('file');
        }

        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], $this->files());
    }

    public function test_empty_files_and_oversized_files_are_refused(): void
    {
        $this->upload($this->alice, 'empty.txt', '')->assertUnprocessable()->assertJsonValidationErrors('file');

        config(['tickets.attachments.max_file_kb' => 1]);
        $this->upload($this->alice, 'big.txt', str_repeat('a', 2048))->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_common_support_files_are_accepted(): void
    {
        $files = [
            ['notes.txt', 'hello world'],
            ['app.log', "2026-11-02 ERROR failed\n"],
            ['values.yaml', "a: 1\nb:\n  - x\n"],
            ['data.json', '{"a":1}'],
            ['data.csv', "a,b\n1,2\n"],
            ['doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
            ['bundle.zip', base64_decode(self::ZIP)],
        ];

        foreach ($files as [$name, $content]) {
            $this->upload($this->alice, $name, $content)->assertCreated();
        }

        $this->assertSame(count($files), TicketAttachment::count());
    }

    public function test_the_browsers_file_name_cannot_do_anything_clever(): void
    {
        $attachment = $this->draft($this->alice, '../../etc/passwd.txt', 'hello');
        $this->assertSame('passwd.txt', $attachment->original_name);
        $this->assertStringStartsWith($this->orgA->id.'/', $attachment->path);

        $attachment = $this->draft($this->alice, "bad\x07\x1Fname.txt", 'hello');
        $this->assertSame('badname.txt', $attachment->original_name);
    }

    public function test_a_draft_can_only_be_opened_and_removed_by_the_person_who_uploaded_it(): void
    {
        $draft = $this->draft($this->alice);

        $this->actingAs($this->alice)->get($draft->url())->assertOk();
        // A colleague in the same organization cannot see it, nor can another organization
        $this->actingAs($this->bob)->get($draft->url())->assertNotFound();
        $this->actingAs($this->carol)->get($draft->url())->assertNotFound();
        $this->actingAs($this->bob)->delete('/support/tickets/uploads/'.$draft->id)->assertNotFound();
        Storage::disk('tickets')->assertExists($draft->path);

        $this->actingAs($this->alice)->delete('/support/tickets/uploads/'.$draft->id)->assertNoContent();
        $this->assertNull(TicketAttachment::find($draft->id));
        Storage::disk('tickets')->assertMissing($draft->path);
    }

    public function test_a_sent_file_cannot_be_discarded_as_a_draft(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $draft = $this->draft($this->alice);
        $this->reply($ticket, $this->alice, '<p>See file</p>', attachmentIds: [$draft->id]);

        $this->actingAs($this->alice)->delete('/support/tickets/uploads/'.$draft->id)->assertNotFound();
        $this->assertNotNull(TicketAttachment::find($draft->id));
    }

    public function test_a_ticket_can_be_created_with_attachments(): void
    {
        $draft = $this->draft($this->alice, 'error.log', "ERROR boom\n");

        $this->actingAs($this->alice)->post('/support/tickets', [
            'subject' => 'It broke',
            'body' => '<p>Log attached</p>',
            'attachment_ids' => [$draft->id],
        ])->assertRedirect();

        $ticket = Ticket::firstOrFail();
        $draft->refresh();
        $this->assertSame($ticket->id, $draft->ticket_id);
        $this->assertSame($ticket->messages()->first()->id, $draft->ticket_message_id);
        $this->assertFalse($draft->inline);
    }

    public function test_replies_carry_attachments_and_everyone_involved_can_open_them(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $draft = $this->draft($this->bob, 'report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->actingAs($this->bob)->post('/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<p>Report</p>', 'attachment_ids' => [$draft->id],
        ])->assertRedirect();

        // Alice (same organization) sees the file on the page and can download it
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertInertia(fn ($page) => $page
                ->where('messages.1.attachments.0.name', 'report.pdf')
                ->where('messages.1.attachments.0.url', $draft->url())
                ->missing('messages.1.attachments.0.path'));
        $this->actingAs($this->alice)->get($draft->url())->assertOk();

        // Staff can too; another organization cannot
        $this->actingAs($this->admin)->get($draft->url())->assertOk();
        $this->actingAs($this->carol)->get($draft->url())->assertNotFound();
    }

    public function test_attachments_on_internal_notes_are_hidden_from_customers(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $draft = $this->draft($this->admin, 'diagnosis.txt', 'internal findings');

        $this->actingAs($this->admin)->post('/admin/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<p>Private</p>', 'internal' => true, 'attachment_ids' => [$draft->id],
        ])->assertRedirect();

        $this->actingAs($this->admin)->get($draft->url())->assertOk();
        // Not the requester, not a colleague
        $this->actingAs($this->alice)->get($draft->url())->assertNotFound();
        $this->actingAs($this->bob)->get($draft->url())->assertNotFound();
        $this->actingAs($this->alice)->get('/support/tickets/'.$ticket->number)
            ->assertDontSee('diagnosis.txt');
    }

    public function test_someone_elses_draft_cannot_be_attached(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $bobs = $this->draft($this->bob);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<p>Stolen</p>', 'attachment_ids' => [$bobs->id],
        ])->assertSessionHasErrors('attachment_ids');

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertNull($bobs->fresh()->ticket_message_id);
    }

    public function test_a_file_that_was_already_sent_cannot_be_attached_again(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $draft = $this->draft($this->alice);
        $this->reply($ticket, $this->alice, '<p>First</p>', attachmentIds: [$draft->id]);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<p>Again</p>', 'attachment_ids' => [$draft->id],
        ])->assertSessionHasErrors('attachment_ids');
    }

    public function test_a_failed_send_keeps_the_uploads(): void
    {
        $draft = $this->draft($this->alice);

        // Missing subject: the form is rejected, nothing is lost
        $this->actingAs($this->alice)->post('/support/tickets', ['subject' => '', 'body' => '<p>x</p>', 'attachment_ids' => [$draft->id]])
            ->assertSessionHasErrors('subject');

        $this->assertNotNull(TicketAttachment::find($draft->id));
        Storage::disk('tickets')->assertExists($draft->path);
    }

    public function test_message_limits_are_enforced(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $a = $this->draft($this->alice, 'a.txt', str_repeat('a', 700));
        $b = $this->draft($this->alice, 'b.txt', str_repeat('b', 700));

        config(['tickets.attachments.max_files' => 1]);
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>x</p>', 'attachment_ids' => [$a->id, $b->id]])
            ->assertSessionHasErrors('attachment_ids');

        config(['tickets.attachments.max_files' => 10, 'tickets.attachments.max_message_kb' => 1]);
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p>x</p>', 'attachment_ids' => [$a->id, $b->id]])
            ->assertSessionHasErrors('attachment_ids');

        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_only_so_many_uploads_can_wait_to_be_sent(): void
    {
        config(['tickets.attachments.max_drafts_per_user' => 2]);

        $this->upload($this->alice, 'a.txt', 'a')->assertCreated();
        $this->upload($this->alice, 'b.txt', 'b')->assertCreated();
        $this->upload($this->alice, 'c.txt', 'c')->assertUnprocessable();
        // The limit is per person
        $this->upload($this->bob, 'd.txt', 'd')->assertCreated();
    }

    public function test_downloads_cannot_be_turned_into_something_the_browser_runs(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $image = $this->draft($this->alice);
        $pdf = $this->draft($this->alice, 'doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $text = $this->draft($this->alice, 'notes.txt', '<script>alert(1)</script>');
        $this->reply($ticket, $this->alice, '<p>Files</p>', attachmentIds: [$image->id, $pdf->id, $text->id]);

        $shown = $this->actingAs($this->alice)->get($image->url());
        $shown->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/png', $shown->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $shown->headers->get('Content-Disposition'));
        // A strict policy of the file's own, not replaced by the page's
        $this->assertStringContainsString('sandbox', $shown->headers->get('Content-Security-Policy'));

        // Only raster images are ever shown in the browser
        $this->assertStringStartsWith('attachment', $this->actingAs($this->alice)->get($pdf->url())->headers->get('Content-Disposition'));
        $page = $this->actingAs($this->alice)->get($text->url());
        $this->assertStringStartsWith('attachment', $page->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/plain', $page->headers->get('Content-Type'));

        // ...and ?download=1 forces even an image to download
        $this->assertStringStartsWith('attachment', $this->actingAs($this->alice)->get($image->url().'?download=1')->headers->get('Content-Disposition'));
    }

    public function test_pasted_images_stay_in_the_message_when_they_are_the_senders_own_upload(): void
    {
        $image = $this->draft($this->alice);

        $this->actingAs($this->alice)->post('/support/tickets', [
            'subject' => 'Screenshot',
            'body' => '<p>Look:</p><img src="'.$image->url().'" alt="shot">',
            'attachment_ids' => [$image->id],
        ])->assertRedirect();

        $message = Ticket::firstOrFail()->messages()->first();
        $this->assertStringContainsString('<img src="'.$image->url().'"', $message->body_html);
        $this->assertTrue($image->fresh()->inline);
    }

    public function test_other_images_are_removed_from_messages(): void
    {
        $others = $this->draft($this->carol);
        $ticket = $this->ticketFor($this->alice, [
            'body' => '<p>Hi</p><img src="https://evil.example/pixel.gif"><img src="'.$others->url().'"><img src="data:image/png;base64,AAAA"><img src="/support/tickets/attachments/999999">',
        ]);

        $html = $ticket->messages()->first()->body_html;

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_a_message_can_be_only_a_file_or_only_a_picture(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $file = $this->draft($this->alice, 'only.txt', 'just a file');
        $picture = $this->draft($this->alice);

        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p></p>', 'attachment_ids' => [$file->id]])->assertRedirect();
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', [
            'body' => '<img src="'.$picture->url().'">', 'attachment_ids' => [$picture->id],
        ])->assertRedirect();

        $this->assertSame(3, $ticket->messages()->count());

        // ...but still not nothing
        $this->actingAs($this->alice)->post('/support/tickets/'.$ticket->number.'/messages', ['body' => '<p></p>'])->assertSessionHasErrors('body');
    }

    public function test_editing_a_message_keeps_its_own_images_but_cannot_add_new_ones(): void
    {
        $image = $this->draft($this->alice);
        $stranger = $this->draft($this->bob);
        $ticket = $this->ticketFor($this->alice, ['body' => '<p>Text</p><img src="'.$image->url().'">', 'attachment_ids' => [$image->id]]);
        $message = $ticket->messages()->first();

        $this->actingAs($this->alice)->put('/support/tickets/'.$ticket->number.'/messages/'.$message->id, [
            'body' => '<p>Edited</p><img src="'.$image->url().'"><img src="'.$stranger->url().'">',
        ])->assertRedirect();

        $html = $message->fresh()->body_html;
        $this->assertStringContainsString($image->url(), $html);
        $this->assertStringNotContainsString($stranger->url(), $html);
        $this->assertStringContainsString('Edited', $html);
    }

    public function test_another_organization_cannot_open_files_by_guessing_ids(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $draft = $this->draft($this->alice, 'secret.txt', 'private');
        $this->reply($ticket, $this->alice, '<p>x</p>', attachmentIds: [$draft->id]);

        foreach ([$this->carol] as $outsider) {
            $this->actingAs($outsider)->get($draft->url())->assertNotFound();
            $this->actingAs($outsider)->get('/support/tickets/attachments/'.($draft->id + 100))->assertNotFound();
        }
    }

    public function test_a_scanner_can_reject_files(): void
    {
        config(['tickets.attachments.scanner' => RejectEverythingScanner::class]);

        $this->upload($this->alice)->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], $this->files());
    }

    public function test_old_uploads_that_were_never_sent_are_deleted(): void
    {
        $ticket = $this->ticketFor($this->alice);
        $old = $this->draft($this->alice, 'old.txt', 'old');
        $fresh = $this->draft($this->alice, 'fresh.txt', 'fresh');
        $sent = $this->draft($this->alice, 'sent.txt', 'sent');
        $this->reply($ticket, $this->alice, '<p>x</p>', attachmentIds: [$sent->id]);

        $old->forceFill(['created_at' => now()->subHours(25)])->save();
        $sent->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->assertSame(1, app(AttachmentService::class)->pruneOrphans());

        $this->assertNull(TicketAttachment::find($old->id));
        Storage::disk('tickets')->assertMissing($old->path);
        $this->assertNotNull(TicketAttachment::find($fresh->id));
        // Anything that was sent stays, however old
        $this->assertNotNull(TicketAttachment::find($sent->id));
        Storage::disk('tickets')->assertExists($sent->path);
    }
}
