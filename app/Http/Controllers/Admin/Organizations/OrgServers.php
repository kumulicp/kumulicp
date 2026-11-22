<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgServer;
use Closure;
use Illuminate\Http\Request;

class OrgServers extends Controller
{
    public function index(Organization $organization)
    {
        $orgServers = $organization->servers()->with('server', 'backup_server.server')->get();

        return inertia()->render('Admin/Organizations/Servers/ServersList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'org_servers' => $orgServers->map(fn (OrgServer $orgServer) => [
                'id' => $orgServer->id,
                'name' => $orgServer->server->name,
                'type' => $orgServer->server->type,
                'is_backup_server' => $orgServer->server->is_backup_server,
                'backup_server_id' => $orgServer->backup_server_id,
                'backup_server_name' => $orgServer->backup_server?->server->name,
            ])->values(),
            // A server is backed up by a backup server of its own type. The page only offers
            // the ones of the type of the server being edited.
            'backup_servers' => $orgServers
                ->filter(fn (OrgServer $orgServer) => $orgServer->server->is_backup_server)
                ->map(fn (OrgServer $orgServer) => [
                    'id' => $orgServer->id,
                    'name' => $orgServer->server->name,
                    'type' => $orgServer->server->type,
                ])->values(),
            'breadcrumbs' => [
                [
                    'label' => __('admin.organizations.organizations'),
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => __('admin.servers.servers'),
                ],
            ],
        ]);
    }

    public function update(Request $request, Organization $organization, OrgServer $orgServer)
    {
        abort_unless($orgServer->organization_id === $organization->id, 404);

        $validated = $request->validate([
            'backup_server_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($organization, $orgServer) {
                    $valid = $organization->servers()
                        ->whereKey($value)
                        ->whereHas('server', fn ($query) => $query
                            ->where('is_backup_server', true)
                            ->where('type', $orgServer->server->type))
                        ->exists();

                    if (! $valid) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
        ]);

        $orgServer->backup_server_id = $validated['backup_server_id'] ?? null;
        $orgServer->save();

        return redirect("/admin/organizations/{$organization->id}/servers")->with('success', __('admin.servers.updated', ['server' => $orgServer->server->name]));
    }
}
