<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Tickets\AttachmentPresenter;
use App\Services\Tickets\AttachmentService;
use App\TicketAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Upload, discard and open ticket attachments. Used by customers and staff
 * alike: what a person may open is decided per file in AttachmentService, and
 * anything they may not open is a 404 so existence is not revealed.
 */
class TicketAttachments extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('tickets.attachments.not_received')]);
        }

        $attachment = $this->attachments->store($file, $request->user());

        return response()->json(AttachmentPresenter::one($attachment), 201);
    }

    public function destroy(Request $request, TicketAttachment $attachment): Response
    {
        abort_unless($this->attachments->discardDraft($attachment, $request->user()), 404);

        return response()->noContent();
    }

    public function show(Request $request, TicketAttachment $attachment): SymfonyResponse
    {
        abort_unless($this->attachments->canView($attachment, $request->user()), 404);

        return $this->attachments->response($attachment, $request->boolean('download'));
    }
}
