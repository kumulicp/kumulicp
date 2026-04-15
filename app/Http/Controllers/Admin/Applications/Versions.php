<?php

namespace App\Http\Controllers\Admin\Applications;

use App\Actions\Apps\MassApplicationUpgrade;
use App\Announcement;
use App\Application;
use App\AppVersion;
use App\Http\Controllers\Controller;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Versions extends Controller
{
    public function index(Application $app)
    {
        $versions = $app->versions()->orderBy('name', 'desc')->get();

        return inertia()->render('Admin/Applications/Versions/VersionsList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'versions' => $versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version' => $version->name,
                    'status' => $version->status,
                    'updated_at' => (new Carbon($version->updated_at))->format('Y-m-d h:m:s'),
                    'created_at' => (new Carbon($version->created_at))->format('Y-m-d h:m:s'),
                ];
            }),
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => __('admin.applications.apps'),
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'label' => __('admin.applications.versions.versions'),
                ],
            ],
        ]);
    }

    public function store(Request $request, Application $app)
    {
        /* Validate */
        $validatedData = $request->validate([
            'version' => 'required|not_regex:/\//',
            'copy_from' => 'required|in:recommendations,previous_version,none',
            'copy_version' => 'exclude_unless:copy_from,previous_version|required|exists:app_versions,id',
        ]);
        if ($validatedData['copy_from'] === 'previous_version' && is_numeric($validatedData['copy_version'])) {
            $copy_version = AppVersion::find($validatedData['copy_version']);
            $settings = $copy_version->settings;
            $admin_path = $copy_version->admin_path;
            $roles = $copy_version->roles;
        } elseif ($validatedData['copy_from'] === 'recommendations') {
            $recommendations = ApplicationFacade::profile($app)->recommendations();
            $settings = [
                'chart_name' => Arr::get($recommendations, '', null),
                'chart_version' => Arr::get($recommendations, 'helm_chart_version', null),
                'helm_repo_name' => Arr::get($recommendations, 'helm_chart_name', null),
                'image_repo_name' => Arr::get($recommendations, 'image_repo', null),
                'image_registry' => Arr::get($recommendations, 'image_registry', null),
            ];
            $admin_path = Arr::get($recommendations, 'admin_path', null);
            $roles = Arr::get($recommendations, 'roles', null);
        } else {
            $settings = null;
            $admin_path = null;
            $roles = null;
        }

        $app_version = new AppVersion;
        $app_version->application_id = $app->id;
        $app_version->name = $request->input('version');
        $app_version->settings = $settings;
        $app_version->roles = $roles;
        $app_version->status = 'deactivated';
        $app_version->admin_path = $admin_path;
        $app_version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$validatedData['version'])->with('success', __('admin.applications.versions.added', ['version' => $version->name]));
    }

    public function edit(Application $app, AppVersion $version)
    {
        $announcements = Announcement::orderBy('created_at', 'desc')->limit(10)->get();
        $roles = $app->roles;

        return inertia()->render('Admin/Applications/Versions/VersionEdit', [
            'version' => [
                'id' => $version->id,
                'version' => $version->name,
                'helm_repo_name' => $version->setting('helm_repo_name'),
                'image_repo_name' => $version->setting('image_repo_name'),
                'image_registry' => $version->setting('image_registry'),
                'chart_version' => $version->setting('chart_version'),
                'chart_name' => $version->setting('chart_name'),
                'admin_path' => $version->admin_path,
                'announcement_location' => $version->announcement_location,
                'announcement_id' => $version->announcement_id,
                'announcement_url' => $version->announcement_url,
                'default_admin_roles' => Arr::get($version->roles, 'default_admin_groups', []),
                'default_user_roles' => Arr::get($version->roles, 'default_user_groups', []),
                'toggle' => [
                    'state' => $version->status == 'active' ? 'disable' : 'enable',
                    'label' => $version->status == 'active' ? 'Disable' : 'Enable',
                ],
            ],
            'app' => [
                'slug' => $app->slug,
                'name' => $app->name,
                'id' => $app->id,
            ],
            'recommendations' => collect(ApplicationFacade::profile($app)->recommendations())->map(function (string $value, string $key) {
                return [
                    'name' => Str::headline($key),
                    'value' => $value,
                ];
            })->values(),
            'announcements' => $announcements->count() > 0 ? $announcements->map(function ($announcement) {
                return [
                    'value' => $announcement->id,
                    'text' => $announcement->title,
                ];
            }) : [],
            'roles' => $roles ? $roles->map(function ($role) {
                return [
                    'text' => $role->label,
                    'value' => $role->id,
                ];
            }) : [],
            'can' => [
                'helm_chart' => ApplicationFacade::profile($app)->isCompatible(['helm_chart']),
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => __('admin.applications.apps'),
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'url' => '/admin/apps/'.$app->slug.'/versions',
                    'label' => __('admin.applications.versions.versions'),
                ],
                [
                    'label' => $version->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Application $app, AppVersion $version)
    {
        /* Validate */
        $validatedData = $request->validate([
            'version' => 'required',
            'default_admin_roles' => 'array|nullable',
            'default_user_roles' => 'array|nullable',
            'admin_path' => 'string|nullable',
            'chart_version' => 'string|nullable',
            'helm_repo_name' => 'string|nullable',
            'image_repo_name' => 'string|nullable',
            'image_registry' => 'nullable|string',
            'announcement_location' => 'required|in:none,remote,local',
            'announcement_id' => 'required_if:announcement_location,local|exists:announcements,id|nullable',
            'announcement_url' => 'required_if:announcement_location,remote|url:http,https|nullable',
        ]);

        $roles = $version->roles;

        $roles['default_admin_groups'] = $request->default_admin_roles;
        $roles['default_user_groups'] = $request->default_user_roles;

        $version->name = $request->input('version');
        $version->roles = $roles;
        $version->admin_path = $request->admin_path;
        $version->updateSettings([
            'chart_version' => $request->input('chart_version'),
            'helm_repo_name' => $request->input('helm_repo_name'),
            'image_repo_name' => $request->input('image_repo_name'),
            'image_registry' => $request->input('image_registry'),
            'chart_name' => $request->input('chart_name'),
        ]);
        $version->announcement_location = $request->input('announcement_location');
        $version->announcement_id = $request->input('announcement_id');
        $version->announcement_url = $request->input('announcement_url');
        $version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$request->input('version'))->with('success', __('admin.applications.versions.updated', ['version' => $version->name]));
    }

    public function enable(Application $app, AppVersion $version)
    {
        $versions = AppVersion::where('application_id', $app->id)->get();

        foreach ($versions as $deactivated_version) {
            $deactivated_version->status = 'deactivated';
            $deactivated_version->save();
        }

        $task = Action::execute(new MassApplicationUpgrade($version));

        $version->status = 'active';
        $version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name)->with('success', __('admin.applications.versions.enabled', ['version' => $version->name]));
    }

    public function disable(Application $app, AppVersion $version)
    {
        $version->status = 'deactivated';
        $version->save();

        $app->enabled = false;
        $app->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name)->with('success', __('admin.applications.versions.disabled', ['version' => $version->name]));
    }

    public function roles(Application $app, AppVersion $version)
    {
        $version_roles = $version->roles ?? [];
        $available_roles = [];

        if ($version_roles && array_key_exists('order', $version_roles) && count($version_roles['order']) > 0) {
            $available_roles = $app->roles()->orderByRaw('field(id,'.implode(',', $version_roles['order']).')')->get();
        } else {
            $available_roles = $app->roles()->get();
        }

        $roles = [
            'selected' => [],
            'available' => [],
        ];

        foreach ($available_roles as $role) {
            if (array_key_exists('order', $version_roles) && in_array($role->id, $version_roles['order'])) {
                $role_type = 'selected';
            } else {
                $role_type = 'available';
            }
            array_push($roles[$role_type], [
                'id' => $role->id,
                'name' => $role->category.' '.$role->name,
                'order' => $role->id,
                'fixed' => false,
            ]);
        }

        return inertia()->render('Admin/Applications/Versions/VersionRoles', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'version' => [
                'id' => $version->id,
                'version' => $version->name,
                'roles' => $roles,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => __('admin.applications.apps'),
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'url' => '/admin/apps/'.$app->slug.'/versions',
                    'label' => __('admin.applications.versions.versions'),
                ],
                [
                    'label' => $version->name,
                ],
            ],
        ]);
    }

    public function updateRoles(Request $request, Application $app, AppVersion $version)
    {
        /* Validate */
        $validatedData = $request->validate([
            'order' => 'array',
        ]);

        $order = [];

        foreach ($validatedData['order'] as $id) {
            $order[] = (int) $id;
        }

        $roles['order'] = $order;
        $version->roles = $roles;
        $version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name.'/roles')->with('success', __('admin.applications.versions.order_updated'));
    }
}
