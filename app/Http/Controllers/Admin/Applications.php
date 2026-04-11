<?php

namespace App\Http\Controllers\Admin;

use App\Application;
use App\AppPlan;
use App\AppVersion;
use App\Enums\AccessType;
use App\Http\Controllers\Controller;
use App\Support\Facades\Application as ApplicationFacade;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Applications extends Controller
{
    public function index()
    {
        $apps = ApplicationFacade::all();

        $apps = Application::paginate(20);

        return inertia()->render('Admin/Applications/AppsList', [
            'apps' => $apps->map(function ($app) {
                return [
                    'id' => $app->id,
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'status' => $app->enabled ? 'Enabled' : 'Disabled',
                ];
            }),
            'meta' => [
                'total' => $apps->total(),
                'pages' => $apps->lastPage(),
                'page' => $apps->currentPage(),
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Apps',
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {

        /* Validate */
        $validatedData = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|alpha_dash|string|lowercase|unique:applications,slug',
            'category' => 'required|string',
            'description' => 'required|string',
        ]);

        $name = $request->name;
        $slug = Str::snake($request->slug);

        $app = new Application;
        $app->name = $name;
        $app->slug = $slug;
        $app->category = $request->category;
        $app->short_description = $request->description;
        $app->description = $request->description;
        $app->enabled = false;
        $app->save();

        $version = new AppVersion;
        $version->name = '1.0';
        $version->application_id = $app->id;
        $version->roles = [];
        $version->settings = [];
        $version->status = 'deactivated';
        $version->save();

        $plan = new AppPlan;
        $plan->name = __('admin.applications.plans.first_plan_name');
        $plan->application_id = $app->id;
        $plan->description = __('admin.applications.plans.first_plan_description');
        $plan->features = [];
        $plan->display_order = 1;
        $plan->settings = [
            'base' => [],
            'standard' => [],
            'basic' => [],
            'storage' => [],
            'application' => [],
        ];
        $plan->save();

        return redirect("/admin/apps/{$slug}")->with('success', '');
    }

    public function show(Application $app)
    {
        return inertia()->render('Admin/Applications/AppView', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'category' => $app->category,
                'parent_app' => [
                    'id' => $app->parent_app_id != 0 ? $app->parent_app_id : null,
                ],
                'access_type' => $app->access_type,
                'short_description' => $app->short_description,
                'description' => $app->description,
                'enabled' => $app->enabled == 1 ? true : false,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                ],
            ],
        ]);
    }

    public function edit(Application $app)
    {
        $apps = Application::all();
        $default_version = $app->versions()->where('status', 'active')->first();

        return inertia()->render('Admin/Applications/AppEdit', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'category' => $app->category,
                'parent_app' => [
                    'id' => $app->parent_app_id != 0 ? $app->parent_app_id : null,
                ],
                'primary_domain_allowed' => $app->primary_domain_allowed,
                'domain_option' => $app->domain_option,
                'access_type' => $app->access_type,
                'short_description' => $app->short_description,
                'description' => $app->description,
                'can_update_domain' => $app->can_update_domain,
                'enabled' => $app->enabled == 1 ? true : false,
                'toggle' => [
                    'state' => $app->enabled ? 'disable' : 'enable',
                    'label' => $app->enabled ? 'Disable' : 'Enable',
                ],
                'default_version' => $default_version ? [
                    'id' => $default_version->id,
                    'version' => $default_version->name,
                ] : [],
                'versions' => $app->versions->map(function ($version) {
                    return [
                        'id' => $version->id,
                        'version' => $version->name,
                    ];
                }),
            ],
            'apps' => $apps->map(function ($app) {
                return [
                    'value' => $app->id,
                    'text' => $app->name,
                ];
            }),
            'breadcrumbs' => [
                [
                    'url' => '/admin/apps',
                    'label' => 'Apps',
                ],
                [
                    'label' => $app->name,
                ],
            ],
        ]);
    }

    public function update(Request $request, Application $app)
    {
        /* Validate */
        $validatedData = $request->validate([
            'name' => 'required',
            'category' => 'required|string|max:100',
            'image' => 'nullable|file|image|mimes:png',
            'parent_app' => 'nullable|numeric',
            'access_type' => ['required', 'string', 'max:15', Rule::enum(AccessType::class)],
            'short_description' => 'required',
            'description' => 'required',
            'primary_domain_allowed' => 'boolean',
            'domain_option' => 'required|in:none,all,subdomains,primary,base,parent',
            'can_update_domain' => 'boolean',
        ]);

        if ($request->file('image')) {
            $appImageName = $app->slug.'.png';
            $appImagePath = $request->file('image')->storeAs('images', $appImageName);
        }
        $app->name = $request->name;
        $app->category = $request->category;
        $app->short_description = $request->short_description;
        $app->description = $request->description;
        $app->parent_app_id = $request->parent_app ?? 0;
        $app->access_type = $request->access_type;
        $app->primary_domain_allowed = $request->primary_domain_allowed ?? false;
        $app->domain_option = $request->domain_option;
        $app->can_update_domain = $request->can_update_domain;
        $app->save();

        return redirect("/admin/apps/{$app->slug}/edit")->with('success', $app->name.' has been updated!');
    }

    public function enable(Request $request, Application $app)
    {
        $validated = $request->validate([
            'version' => [
                function (string $attribute, mixed $value, Closure $fail) use ($app) {
                    if (is_null($app->active_version()) && is_null($value)) {
                        $fail("The {$attribute} is required to enable this app.");
                    }
                },
            ],
        ]);
        $version = $app->versions()->where('id', $validated['version'])->first();

        if (! $version) {
            $version = $app->versions()->where('status', 'active')->first();
        }

        if ($version) {
            $version->status = 'active';
            $version->save();

            $app->enabled = 1;
            $app->save();

            return redirect('/admin/apps/'.$app->slug.'/edit')->with('success', 'Application is enabled. Organizations can now activate and begin using it.');
        } else {
            return redirect('/admin/apps/'.$app->slug.'/edit')->with('error', 'This application cannot be enabled yet. You must first enable a default version.');
        }
    }

    public function disable(Request $request, Application $app)
    {
        $app->enabled = 0;
        $app->save();

        return redirect('/admin/apps/'.$app->slug.'/edit')->with('success', 'Application was disabled. Organizations who have activated it will continue to be able to use it. Organizations will no longer be able to do fresh installs it or run custom updates.');
    }
}
