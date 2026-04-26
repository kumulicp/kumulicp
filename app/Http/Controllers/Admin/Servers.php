<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Servers\ServerActivate;
use App\Application;
use App\AppPlan;
use App\Http\Controllers\Controller;
use App\Server;
use App\Support\Facades\Action;
use App\Support\Facades\ServerInterface;
use Illuminate\Http\Request;

class Servers extends Controller
{
    public function index()
    {
        $servers = Server::all();

        return inertia()->render('Admin/Servers/ServersList', [
            'servers' => $servers->map(function ($server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'host' => $server->host,
                    'type' => $server->type,
                    'status' => $server->status,
                ];
            }),
            'applications' => Application::all()->map(function ($app) {
                return [
                    'text' => $app->name,
                    'value' => $app->slug,
                ];
            }),
            'interfaces' => [
                'web' => ServerInterface::all('web'),
                'database' => ServerInterface::all('database'),
                'email' => ServerInterface::all('email'),
                'sso' => ServerInterface::all('sso'),
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Servers',
                ],
            ],
        ]);
    }

    public function show(Server $server)
    {
        return inertia()->render('Admin/Servers/ServerView', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'address' => $server->address,
                'api_key' => $server->api_key,
                'ip' => $server->ip,
                'internal_address' => $server->internal_address,
                'type' => $server->type,
                'interface' => $server->interface,
                'default_web_server' => $server->default_web_server,
                'default_email_server' => $server->default_email_server,
                'default_database_server' => $server->default_database_server,
                'settings' => $server->settings,
                'status' => $server->status,
                'default' => ($server->default_web_server || $server->default_email_server || $server->default_database_server),
                'app_instance' => $server->app_instance ? [
                    'id' => $server->app_instance->id,
                    'label' => $server->app_instance->label,
                    'organization_id' => $server->app_instance->organization_id,
                ] : [],
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/server/servers',
                    'label' => __('admin.servers.servers'),
                ],
                [
                    'label' => $server->name,
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'interface' => 'required|string',
            'location' => 'required|in:external,internal',
            'app' => 'nullable|required_if:location,internal|exists:applications,slug',
            'plan' => 'nullable|required_if:location,internal|exists:app_plans,id',
        ]);

        $server = new Server;
        $server->name = $validated['name'];
        $server->type = $validated['type'];
        $server->interface = $validated['interface'];
        $server->status = 'active';
        $server->save();

        $app = Application::where('slug', $validated['app'])->first();
        $plan = AppPlan::find($validated['plan']);

        if ($validated['location'] === 'internal') {
            Action::execute(new ServerActivate($server, $app, $plan, $validated['name']));
        }

        return redirect("/admin/server/servers/{$server->id}/edit")->with('success', __('admin.servers.added', ['server' => $server->name]));
    }

    public function edit(Server $server)
    {
        $successful_test_count = $server->type == 'email' ? $server->successfulBaseTests()->count() : $server->successfulAppTests()->count();
        $server_description = ServerInterface::profile($server)->description();

        return inertia()->render('Admin/Servers/ServerEdit', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'address' => $server->address,
                'api_key' => $server->api_key,
                'api_secret' => $server->api_secret,
                'ip' => $server->ip,
                'internal_address' => $server->internal_address,
                'type' => $server->type,
                'interface' => $server->interface,
                'default' => ($server->default_web_server || $server->default_email_server || $server->default_database_server),
                'settings' => $server->settings ? $server->settings : [],
                'status' => $server->status,
                'description' => $server_description,
                'app_instance' => $server->app_instance ? [
                    'id' => $server->app_instance->id,
                    'label' => $server->app_instance->label,
                    'organization_id' => $server->app_instance->organization_id,
                ] : [],
                'default_backup_server' => $server->default_backup_server_id,
                'is_backup_server' => $server->is_backup_server,
            ],
            'backup_servers' => Server::where('is_backup_server', true)->where('type', $server->type)->whereNot('id', $server->id)->get()->map(function ($server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                ];
            })->push([
                'id' => $server->id,
                'name' => 'Self',
            ])->push([
                'id' => null,
                'name' => 'None',
            ]),
            'can' => [
                'activate' => $successful_test_count > 0,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/server/servers',
                    'label' => __('admin.servers.servers'),
                ],
                [
                    'url' => '/admin/server/servers/'.$server->id,
                    'label' => $server->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Server $server)
    {
        $validated = $request->validate([
            'name' => 'string|required',
            'host' => 'string|required',
            'address' => 'string|required',
            'api_key' => 'string|required',
            'api_secret' => 'string|required',
            'ip' => 'string|required',
            'internal_address' => 'string|required',
            'settings' => 'array|nullable',
            'default_backup_server' => 'nullable|exists:servers,id',
            'is_backup_server' => 'nullable|boolean',
        ]);

        $server->name = $validated['name'];
        $server->host = $validated['host'];
        $server->address = $validated['address'];
        $server->api_key = $validated['api_key']; // Needs to be encrypted
        $server->api_secret = $validated['api_secret']; // Needs to be encrypted
        $server->ip = $validated['ip'];
        $server->internal_address = $validated['internal_address'];
        $server->settings = $validated['settings'];
        $server->default_backup_server_id = $validated['default_backup_server'];
        $server->is_backup_server = $validated['is_backup_server'];
        $server->save();

        return redirect('/admin/server/servers/'.$server->id)->with('success', __('admin.servers.updated', ['server' => $server->name]));
    }

    public function confirm(Server $server)
    {
        if ($server->tests()->count() > 0) {
            $server->status = 'active';
            $server->save();

            return redirect("/admin/server/servers/{$server->id}")->with('success', __('admin.servers.validated', ['server' => $server->name]));
        }

        return redirect("/admin/server/servers/{$server->id}")->with('error', 'No successfully run tests found. You must successfully run a test before you can enable this server.');
    }

    public function set_default($server)
    {
        $server = Server::find($server);

        $default_server_type = "default_{$server->type}_server";

        if ($server) {
            $current_default_server = Server::where($default_server_type, 1)->update([$default_server_type => 0]);
            $server->$default_server_type = 1;
            $server->save();
        }

        return redirect("/admin/server/servers/{$server->id}")->with('success', __('admin.servers.is_default', ['server' => $server->name]));
    }

    public function chart(Server $server)
    {
        return inertia()->render('Admin/Servers/ServerChart', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'chart' => $server->chart,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/server/servers',
                    'label' => __('admin.servers.servers'),
                ],
                [
                    'url' => '/admin/server/servers/'.$server->id,
                    'label' => $server->name,
                ],
                [
                    'label' => __('admin.servers.chart'),
                ],
            ],
        ]);
    }

    public function destroy(Server $server)
    {
        if ($server->status == 'inactive') {
            $server->delete();
        }

        return redirect('/admin/server/servers')->with('success', __('admin.servers.deleted'));
    }
}
