<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\SecretStore;
use App\Support\Facades\SecretStore as SecretStoreFacade;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SecretStores extends Controller
{
    public function index()
    {
        $secret_stores = SecretStore::orderBy('name')->get();

        return inertia('Admin/Settings/SecretStores/SecretStoresList', [
            'secret_stores' => $secret_stores->map(function ($secret_store) {
                return [
                    'id' => $secret_store->id,
                    'name' => $secret_store->name,
                    'driver' => $secret_store->driver,
                    'is_default' => $secret_store->is_default,
                    'address' => $secret_store->address,
                    'mount_path' => $secret_store->mount_path,
                    'namespace' => $secret_store->namespace,
                    'server_count' => $secret_store->servers()->count(),
                    'can_delete' => ! $secret_store->is_default && ! $secret_store->inUse(),
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                [
                    'label' => __('admin.secretStores.secretStores'),
                ],
            ],
        ]);
    }

    protected function rules(?SecretStore $secret_store = null): array
    {
        // role_id/secret_id are hidden from the client once set, so they can
        // only be required on creation - on update, a blank field means
        // "keep the current value" (see update() below).
        $credentialsRequired = is_null($secret_store) ? 'required_if:driver,openbao' : 'nullable';

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('secret_stores', 'name')->ignore($secret_store)],
            'driver' => ['required', 'string', 'in:database,openbao'],
            'address' => ['nullable', 'string', 'max:255', 'required_if:driver,openbao'],
            'role_id' => ['nullable', 'string', 'max:1024', $credentialsRequired],
            'secret_id' => ['nullable', 'string', 'max:1024', $credentialsRequired],
            'mount_path' => ['nullable', 'string', 'max:255'],
            'namespace' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        SecretStore::create($validated);

        return redirect()->back()->with('success', __('admin.secretStores.added'));
    }

    public function update(Request $request, SecretStore $secretStore)
    {
        $validated = $request->validate($this->rules($secretStore));

        $secretStore->name = $validated['name'];
        $secretStore->driver = $validated['driver'];
        $secretStore->address = $validated['address'] ?? null;
        $secretStore->mount_path = $validated['mount_path'] ?? null;
        $secretStore->namespace = $validated['namespace'] ?? null;

        // role_id/secret_id aren't sent back to the client (hidden), so only
        // overwrite them when the form actually submitted new values.
        if (! empty($validated['role_id'])) {
            $secretStore->role_id = $validated['role_id'];
        }
        if (! empty($validated['secret_id'])) {
            $secretStore->secret_id = $validated['secret_id'];
        }

        $secretStore->save();

        return redirect()->back()->with('success', __('admin.secretStores.updated'));
    }

    public function destroy(SecretStore $secretStore)
    {
        if ($secretStore->is_default) {
            return redirect()->back()->with('error', __('admin.secretStores.cannotDeleteDefault'));
        }

        if ($secretStore->inUse()) {
            return redirect()->back()->with('error', __('admin.secretStores.inUse'));
        }

        $secretStore->delete();

        return redirect()->back()->with('success', __('admin.secretStores.deleted'));
    }

    public function testConnection(SecretStore $secretStore)
    {
        if (SecretStoreFacade::driver($secretStore)->testConnection()) {
            return redirect()->back()->with('success', __('admin.secretStores.testSucceeded'));
        }

        return redirect()->back()->with('error', __('admin.secretStores.testFailed'));
    }
}
