<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketImpact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Customer-side edits to a ticket.
 */
class UpdateTicketRequest extends FormRequest
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
            'subject' => ['sometimes', 'required', 'string', 'max:'.config('tickets.max_subject_length')],
            'impact' => ['sometimes', 'required', Rule::enum(TicketImpact::class)],
            'customer_assignee_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
