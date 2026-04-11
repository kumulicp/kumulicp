<?php

namespace App\Http\Controllers\Admin\Organizations;

use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpgrade;
use App\AppInstance;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\Organization;
use App\Support\Facades\Action;
use Illuminate\Http\Request;

class Applications extends Controller
{
    public function index(Organization $organization)
    {
        return inertia()->render('Admin/Organizations/Apps/AppsList', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'contact_name' => $organization->contact_first_name.' '.$organization->contact_last_name,
                'contact_email' => $organization->contact_email,
                'status' => $organization->status,
            ],
            'apps' => $organization->app_instances()->with(['organization', 'application', 'version', 'primary_domain'])->get()->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->label,
                    'version' => $app->version->name,
                    'domain' => [
                        'name' => $app->domain(),
                    ],
                    'status' => $app->status,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Apps',
                ],
            ],
        ]);
    }

    public function show(Organization $organization, AppInstance $app)
    {
        return inertia()->render('Admin/Organizations/Apps/AppView', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'version' => [
                    'id' => $app->version->id,
                    'name' => $app->version->name,
                ],
                'settings' => $app->settings,
                'status' => $app->status,
                'password' => $app->api_password(),
                'plan' => [
                    'id' => $app->plan->id,
                    'name' => $app->plan->name,
                ],
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Apps',
                    'url' => '/admin/organizations/'.$organization->id.'/apps',
                ],
                [
                    'label' => $app->application->name,
                ],
            ],
        ]);
    }

    public function edit(Organization $organization, AppInstance $app)
    {
        $versions = AppVersion::where('application_id', $app->application_id)
            ->where('name', '>', $app->version->name)
            ->get();

        return inertia()->render('Admin/Organizations/Apps/AppEdit', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'app' => [
                'id' => $app->id,
                'name' => $app->application->name,
                'version' => [
                    'id' => $app->version->id,
                    'name' => $app->version->name,
                ],
                'settings' => $app->settings,
                'status' => $app->status,
                'password' => $app->api_password(),
                'plan' => [
                    'id' => $app->plan->id,
                    'name' => $app->plan->name,
                ],
            ],
            'versions' => $versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version' => $version->name,
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => 'Organizations',
                    'url' => '/admin/organizations',
                ],
                [
                    'label' => $organization->name,
                    'url' => '/admin/organizations/'.$organization->id,
                ],
                [
                    'label' => 'Apps',
                    'url' => '/admin/organizations/'.$organization->id.'/apps',
                ],
                [
                    'label' => $app->application->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Organization $organization, AppInstance $app)
    {
        $validated = $request->validate([
            'settings' => 'required|json',
        ]);

        $app->settings = json_decode($validated['settings'], true);
        $app->save();

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', 'Settings updated');
    }

    public function update_settings(Organization $organization, AppInstance $app)
    {
        $task = Action::execute(new ApplicationUpdate($app));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', $app->application->name.' is being updated');
    }

    public function run(Organization $organization, AppInstance $app, $action)
    {
        $task = $action->createTask($app);

        return redirect(route('organizations.app', ['organization_id' => $organization->id, 'app' => $app->id]))->with('success', $action->name.' for '.$app->application->name.' was successfully started');
    }

    public function activate(Organization $organization, $app, $version) {}

    public function upgrade(Organization $organization, AppInstance $app, AppVersion $version)
    {
        // TODO: Add check that version is compatible, current or more recent
        if ($version->application_id != $app->application_id) {
            return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('error', 'The version you selected does not exist! Please try again.');
        }

        $notify = true;

        if ($app->version_id === $version->id || $version->announcement_location === 'none') {
            $notify = false;
        }

        Action::execute(new ApplicationUpgrade($app, $version, null, $notify));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', $app->application->name.' is being updated');

    }

    public function delete(Request $request, Organization $organization, AppInstance $app)
    {
        $validated = $request->validate([
            'when' => 'required|in:now,later',
            'start_time' => 'required_if:when,later',
            'end_time' => 'required_if:when,later',
        ]);

        $start = $validated['when'] === 'later' ? $validated['start_time'] : null;
        $end = $validated['when'] === 'later' ? $validated['end_time'] : null;

        $task = Action::execute(new ApplicationDelete($app, start_time: $start, end_time: $end));

        return redirect("/admin/organizations/{$organization->id}/apps/{$app->id}")->with('success', $app->application->name.' is being deleted');
    }
}
