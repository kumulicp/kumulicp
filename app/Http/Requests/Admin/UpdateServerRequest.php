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
        $interface = $server?->interface;

        $rules = [
            'name' => 'string|required',
            'settings' => 'array|nullable',
            'default_backup_server' => 'nullable|exists:servers,id',
            'is_backup_server' => 'nullable|boolean',
        ];

        if ($interface === 'helm_k8s') {
            return array_merge($rules, [
                'k8s_api_server' => 'required|url',
                'k8s_ca_cert' => 'nullable|string',
                'k8s_tls_verify' => 'boolean',
                'k8s_ingress_class' => 'nullable|string',
                'k8s_auth_type' => 'required|in:bearer_token,client_cert',
                // Blank means "leave unchanged" once a value has been saved —
                // only require it the first time this credential is set.
                'k8s_bearer_token' => [
                    Rule::requiredIf(fn () => $this->input('k8s_auth_type') === 'bearer_token' && ! $server?->k8s_bearer_token),
                    'nullable', 'string',
                ],
                'k8s_client_cert' => [
                    Rule::requiredIf(fn () => $this->input('k8s_auth_type') === 'client_cert' && ! $server?->k8s_client_cert),
                    'nullable', 'string',
                ],
                'k8s_client_key' => [
                    Rule::requiredIf(fn () => $this->input('k8s_auth_type') === 'client_cert' && ! $server?->k8s_client_key),
                    'nullable', 'string',
                ],
                'k8s_impersonate_user' => 'nullable|string',
                'k8s_impersonate_group' => 'nullable|string',
            ]);
        }

        return array_merge($rules, [
            'host' => 'string|required',
            'address' => 'string|required',
            'ip' => 'string|required',
            'internal_address' => 'string|required',
            'api_key' => [Rule::requiredIf(fn () => ! $server?->api_key), 'nullable', 'string'],
            'api_secret' => [Rule::requiredIf(fn () => ! $server?->api_secret), 'nullable', 'string'],
        ]);
    }
}
