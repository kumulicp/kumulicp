<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already gated by the 'can:admin' middleware group.
        return true;
    }

    public function rules(): array
    {
        $server = $this->route('server');

        return [
            'name' => 'string|required',
            'host' => 'string|required',
            'address' => 'string|required',
            'ip' => 'string|required',
            'internal_address' => 'string|required',
            'ca_cert' => 'nullable|string',
            'settings' => 'array|nullable',
            'default_backup_server' => 'nullable|exists:servers,id',
            'is_backup_server' => 'nullable|boolean',
            // Blank means "leave unchanged" once a value has been saved —
            // only require it the first time this credential is set. What
            // these hold varies per driver — see each Profile::description().
            'api_key' => [Rule::requiredIf(fn () => ! $server?->api_key), 'nullable', 'string'],
            'api_secret' => [Rule::requiredIf(fn () => ! $server?->api_secret), 'nullable', 'string'],
        ];
    }
}
