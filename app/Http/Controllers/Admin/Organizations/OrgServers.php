<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Http\Controllers\Controller;
use App\Organization;
use App\OrgServer;
use App\Server;
use App\Services\OrgServerService;
use App\Support\ServerChoices;
use Illuminate\Http\Request;

class OrgServers extends Controller
{
    public function index(Organization $organization)
    {
        $orgServers = $organization->servers()->with('server', 'backup_server.server')->get();

        // A server is backed up by a backup server of its own type. Any active backup server
        // can be picked, not only the ones the organization is already on; the one a server
        // has now is always listed.
        $backupServers = $orgServers->pluck('server.type')->unique()
            ->flatMap(fn (string $type) => ServerChoices::for($type, backup: true))
            ->merge($orgServers->map(fn (OrgServer $orgServer) => $orgServer->backup_server?->server)->filter())
            ->unique('id');

        return inertia()->render('Admin/Organizations/Servers/ServersList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'org_servers' => $orgServers->map(fn (OrgServer $orgServer) => [
                'id' => $orgServer->id,
                'name' => $orgServer->server->name,
                'type' => $orgServer->server->type,
                // The Server it is backed up to
                'backup_server_id' => $orgServer->backup_server?->server_id,
                'backup_server_name' => $orgServer->backup_server?->server->name,
            ])->values(),
            'backup_servers' => ServerChoices::options($backupServers),
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
            'backup_server_id' => ServerChoices::rules(
                $orgServer->server->type,
                backup: true,
                keep: $orgServer->backup_server?->server_id,
            ),
        ]);

        // The organization may not be on the backup server yet
        $orgServer->backup_server_id = isset($validated['backup_server_id'])
            ? OrgServerService::forServer($organization, Server::findOrFail($validated['backup_server_id']))->get()->id
            : null;
        $orgServer->save();

        return redirect("/admin/organizations/{$organization->id}/servers")->with('success', __('admin.servers.updated', ['server' => $orgServer->server->name]));
    }
}
