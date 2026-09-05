<?php

namespace App\Http\Controllers\Admin\Settings;

use App\AppVersion;
use App\Http\Controllers\Controller;
use App\RepoSecret;
use Illuminate\Http\Request;

class RepoSecrets extends Controller
{
    public function index()
    {
        $repo_secrets = RepoSecret::orderBy('name')->get();

        return inertia('Admin/Settings/RepoSecrets/RepoSecretsList', [
            'repo_secrets' => $repo_secrets->map(function ($repo_secret) {
                return [
                    'id' => $repo_secret->id,
                    'type' => $repo_secret->type,
                    'name' => $repo_secret->name,
                    'registry' => $repo_secret->registry,
                    'has_auth' => $repo_secret->requiresAuth(),
                    'version_count' => $repo_secret->versions()->count(),
                    'can_delete' => ! $repo_secret->inUse(),
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => __('admin.settings.control_panel_settings'),
                    'url' => '/admin/settings',
                ],
                [
                    'label' => __('admin.repoSecrets.repoSecrets'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:'.RepoSecret::TYPE_IMAGE.','.RepoSecret::TYPE_HELM.','.RepoSecret::TYPE_BOTH],
            'name' => ['required', 'string', 'max:255', 'unique:repo_secrets,name'],
            'registry' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024', 'required_with:username'],
        ]);

        RepoSecret::create($validated);

        return redirect()->back()->with('success', __('admin.repoSecrets.added'));
    }

    public function destroy(RepoSecret $repoSecret)
    {
        if ($repoSecret->inUse()) {
            return redirect()->back()->with('error', __('admin.repoSecrets.inUse'));
        }

        AppVersion::where('pull_secret_id', $repoSecret->id)->update(['pull_secret_id' => null]);
        AppVersion::where('helm_repo_secret_id', $repoSecret->id)->update(['helm_repo_secret_id' => null]);

        $repoSecret->delete();

        return redirect()->back()->with('success', __('admin.repoSecrets.deleted'));
    }

    public function massMigrate(Request $request)
    {
        $validated = $request->validate([
            'from_id' => ['required', 'integer', 'exists:repo_secrets,id', 'different:to_id'],
            'to_id' => ['required', 'integer', 'exists:repo_secrets,id'],
        ]);

        $from = RepoSecret::findOrFail($validated['from_id']);
        $to = RepoSecret::findOrFail($validated['to_id']);

        if ($from->type !== $to->type) {
            return redirect()->back()->with('error', __('admin.repoSecrets.migrateTypeMismatch'));
        }

        if ($from->type !== RepoSecret::TYPE_HELM) {
            AppVersion::where('pull_secret_id', $from->id)->update(['pull_secret_id' => $to->id]);
        }

        if ($from->type !== RepoSecret::TYPE_IMAGE) {
            AppVersion::where('helm_repo_secret_id', $from->id)->update(['helm_repo_secret_id' => $to->id]);
        }

        return redirect()->back()->with('success', __('admin.repoSecrets.migrated'));
    }
}
