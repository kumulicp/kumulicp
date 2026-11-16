<?php

namespace App\Http\Requests\Tickets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A rich text body (reply or edit). Cleaned and length-checked in TicketHtml.
 */
class BodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'internal' => ['sometimes', 'boolean'],
            'attachment_ids' => ['sometimes', 'array', 'max:'.config('tickets.attachments.max_files')],
            'attachment_ids.*' => ['integer'],
        ];
    }
}
