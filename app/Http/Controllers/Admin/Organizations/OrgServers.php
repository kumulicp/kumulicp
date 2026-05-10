<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Http\Controllers\Controller;
use App\OrgServer;
use App\Organization;
use Illuminate\Http\Request;

class OrgServers extends Controller
{
    public function index(Organization $organization)
    {
        $orgServers = $organization->servers()->with('server', 'backup_server.server')->get();

        $backupServers = $orgServers->filter(function ($s) {
            return $s->server->is_backup_server;
        })->map(function ($s) {
            return [
                'id' => $s->id,
                'name' => $s->server->name,
            ];
        })->values();

        return inertia()->render('Admin/Organizations/Servers/ServersList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'org_servers' => $orgServers->map(function ($orgServer) {
                return [
                    'id' => $orgServer->id,
                    'name' => $orgServer->server->name,
                    'type' => $orgServer->server->type,
                    'is_backup_server' => $orgServer->server->is_backup_server,
                    'backup_server_id' => $orgServer->backup_server_id,
                    'backup_server_name' => $orgServer->backup_server?->server->name,
                ];
            })->values(),
            'backup_servers' => $backupServers,
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
        $validated = $request->validate([
            'backup_server_id' => 'nullable|exists:org_servers,id',
        ]);

        if ($orgServer->organization_id !== $organization->id) {
            abort(403);
        }

        $orgServer->backup_server_id = $validated['backup_server_id'] ?? null;
        $orgServer->save();

        return redirect("/admin/organizations/{$organization->id}/servers")->with('success', __('admin.servers.updated'));
    }
}
