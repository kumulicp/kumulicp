<?php

namespace App\Http\Controllers\Admin\Applications;

use App\Application;
use App\AppRole;
use App\Http\Controllers\Controller;
use App\Support\Facades\Application as AppFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Roles extends Controller
{
    public function index(Application $app)
    {
        $role_groups = AppFacade::roles($app->slug);

        return inertia()->render('Admin/Applications/Roles/RolesList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'roles' => $app->roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->label,
                    'access_type' => $role->access_type,
                    'slug' => $role->slug,
                    'status' => $role->status,
                ];
            }),
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'label' => 'Roles',
                ],
            ],
        ]);
    }

    public function edit(Application $app, $role)
    {
        $role = $app->roles()->where('id', $role)->first();
        $features = AppFacade::features($app->slug);

        return inertia()->render('Admin/Applications/Roles/RoleEdit', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'slug' => $role->slug,
                'category' => $role->category,
                'access_type' => $role->access_type,
                'required_features' => $role->required_features,
                'ignore_role' => $role->ignore_role,
                'implied_roles' => $role->implied_roles->map(function ($implied_role) {
                    return $implied_role->id;
                }),
            ],
            'roles' => $app->roles()->where('id', '!=', $role->id)->get()->map(function ($role) {
                return [
                    'id' => $role->id,
                    'label' => $role->label,
                ];
            }),
            'features' => $features->values()->map(function ($feature) {
                return [
                    'text' => $feature->name,
                    'value' => $feature->name,
                ];
            }),
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                    'url' => '/admin/apps/'.$app->slug,
                ],
                [
                    'url' => '/admin/apps/'.$app->slug.'/roles',
                    'label' => 'Roles',
                ],
                [
                    'label' => $role->name,
                ],
            ],
        ]);

        return redirect("admin/apps/{$application_id}/roles");
    }

    public function store(Request $request, Application $app)
    {
        /* Validate */
        $validatedData = $request->validate([
            'label' => 'required|string|max:255',
            'sub_name' => 'required|string|max:100',
            'slug' => 'required|string|max:100',
            'description' => 'required|string|max:500',
            'category' => 'required|string|max:100',
            'access_type' => 'required|string|max:100',
        ]);
        if ($app) {
            $role = new AppRole;
            $role->application_id = $app->id;
            $role->name = $request->sub_name;
            $role->slug = Str::snake($request->slug);
            $role->label = $request->label;
            $role->description = $request->description;
            $role->category = $request->category;
            $role->access_type = $request->access_type;
            $role->status = 'enabled';

            $role->save();

            return redirect('admin/apps/'.$app->slug.'/roles')->with('success', $role->name.' was added successfully!');
        }
    }

    public function update(Request $request, Application $app, $role)
    {
        /* Validate */
        $validatedData = $request->validate([
            'label' => 'required|string|max:255',
            'sub_name' => 'string|max:100|required',
            'slug' => 'string|max:100|required',
            'description' => 'string|max:255|required',
            'category' => 'string|max:100|required',
            'access_type' => 'string|max:100|required|in:disabled,minimal,basic,standard',
            'required_features' => 'nullable|array',
            'ignore_role' => 'boolean',
            'implied_roles' => 'nullable|array',
        ]);
        $role = $app->roles()->where('id', $role)->first();

        if ($role) {
            $role->label = $request->label;
            $role->name = $request->sub_name;
            $role->slug = $request->slug;
            $role->description = $request->description;
            $role->category = $request->category;
            $role->access_type = $request->access_type;
            $role->ignore_role = $request->ignore_role;
            $role->required_features = $request->required_features;
            $role->implied_roles()->sync($validatedData['implied_roles']);
            $role->save();

            return redirect('admin/apps/'.$app->slug.'/roles')->with('success', $role->label.' was updated');
        }

        return redirect('admin/apps/'.$app->slug.'/roles')->with('error', 'App role does not exist');
    }

    public function enable($application_id, $roleid)
    {
        $role = AppRole::find($roleid);
        $role->status = 'enabled';
        $role->save();

        return redirect('admin/apps/'.$application_id.'/roles/'.$roleid.'/edit');
    }

    public function disable($application_id, $roleid)
    {
        $role = AppRole::find($roleid);
        $role->status = 'disabled';
        $role->save();

        return redirect('admin/apps/'.$application_id.'/roles/'.$roleid.'/edit');
    }

    public function remove($application_id, $roleid)
    {
        $role = AppRole::find($roleid);

        // Remove from Version Options List
        // $versions = AppVersion::whereJsonContains('roles->order', $role->id)->get();

        $role->delete();

        return redirect('admin/apps/'.$application_id.'/roles/');
    }
}
