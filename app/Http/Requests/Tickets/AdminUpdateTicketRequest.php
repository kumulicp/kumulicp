<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff edits to a ticket. Any subset of the fields may be sent.
 */
class AdminUpdateTicketRequest extends FormRequest
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
            'lock_version' => ['nullable', 'integer'],
            'subject' => ['sometimes', 'required', 'string', 'max:'.config('tickets.max_subject_length')],
            'impact' => ['sometimes', 'required', Rule::enum(TicketImpact::class)],
            'status' => ['sometimes', 'required', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'required', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'support_assignee_id' => ['sometimes', 'nullable', 'integer'],
            'customer_assignee_id' => ['sometimes', 'nullable', 'integer'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ];
    }
}
