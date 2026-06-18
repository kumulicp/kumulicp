<?php

namespace App\Http\Controllers\Admin\Settings;

use App\AppVersion;
use App\Http\Controllers\Controller;
use App\PullSecret;
use Illuminate\Http\Request;

class PullSecrets extends Controller
{
    public function index()
    {
        $pull_secrets = PullSecret::orderBy('name')->get();

        return inertia('Admin/Settings/PullSecrets/PullSecretsList', [
            'pull_secrets' => $pull_secrets->map(function ($pull_secret) {
                return [
                    'id' => $pull_secret->id,
                    'name' => $pull_secret->name,
                    'registry' => $pull_secret->registry,
                    'has_auth' => $pull_secret->requiresAuth(),
                    'version_count' => $pull_secret->versions()->count(),
                    'can_delete' => ! $pull_secret->inUse(),
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                [
                    'label' => __('admin.pullSecrets.pullSecrets'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:pull_secrets,name'],
            'registry' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024', 'required_with:username'],
        ]);

        PullSecret::create($validated);

        return redirect()->back()->with('success', __('admin.pullSecrets.added'));
    }

    public function destroy(PullSecret $pullSecret)
    {
        if ($pullSecret->inUse()) {
            return redirect()->back()->with('error', __('admin.pullSecrets.inUse'));
        }

        AppVersion::where('pull_secret_id', $pullSecret->id)->update(['pull_secret_id' => null]);

        $pullSecret->delete();

        return redirect()->back()->with('success', __('admin.pullSecrets.deleted'));
    }

    public function massMigrate(Request $request)
    {
        $validated = $request->validate([
            'from_id' => ['required', 'integer', 'exists:pull_secrets,id', 'different:to_id'],
            'to_id' => ['required', 'integer', 'exists:pull_secrets,id'],
        ]);

        AppVersion::where('pull_secret_id', $validated['from_id'])->update([
            'pull_secret_id' => $validated['to_id'],
        ]);

        return redirect()->back()->with('success', __('admin.pullSecrets.migrated'));
    }
}
