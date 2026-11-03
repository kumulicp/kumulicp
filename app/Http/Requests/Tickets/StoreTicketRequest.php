<?php

namespace App\Http\Requests\Tickets;

use App\Enums\TicketImpact;
use App\Enums\TicketType;
use App\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organization_id = $this->user()->organization_id;

        return [
            'subject' => ['required', 'string', 'max:'.config('tickets.max_subject_length')],
            'body' => ['required', 'string'],
            'type' => ['nullable', Rule::enum(TicketType::class)],
            'impact' => ['nullable', Rule::enum(TicketImpact::class)],
            'category_id' => ['nullable', 'integer', Rule::exists('ticket_categories', 'id')->where('active', true)],
            'app_instance_id' => ['nullable', 'integer', Rule::exists('app_instances', 'id')->where('organization_id', $organization_id)],
            'customer_assignee_id' => ['nullable', 'integer'],
            'attachment_ids' => ['sometimes', 'array', 'max:'.config('tickets.attachments.max_files')],
            'attachment_ids.*' => ['integer'],
        ];
    }
}
