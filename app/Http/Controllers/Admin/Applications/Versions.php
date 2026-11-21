<?php

namespace App\Http\Controllers\Admin\Applications;

use App\Actions\Apps\MassApplicationUpgrade;
use App\Announcement;
use App\Application;
use App\AppChart;
use App\AppVersion;
use App\AppVersionGroup;
use App\Console\Calls\CheckVersionUpdates;
use App\Http\Controllers\Controller;
use App\RepoSecret;
use App\Support\Facades\Action;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Versions extends Controller
{
    public function index(Application $app)
    {
        // The versions of one group at a time: the one asked for, else the default
        $group = $app->versionGroups()->find(request()->integer('group')) ?? $app->findDefaultVersionGroup();
        $versions = $app->versions()
            ->when($group, fn ($query) => $query->where('version_group_id', $group->id))
            ->orderBy('name', 'desc')
            ->get();

        return inertia()->render('Admin/Applications/Versions/VersionsList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'group_id' => $group?->id,
            'groups' => $this->groupOptions($app),
            'versions' => $versions->map(function ($version) {
                return [
                    'id' => $version->id,
                    'version' => $version->name,
                    'status' => $version->status,
                    'updated_at' => (new Carbon($version->updated_at))->format('Y-m-d h:m:s'),
                    'created_at' => (new Carbon($version->created_at))->format('Y-m-d h:m:s'),
                    'updates' => $version->updateSummary(),
                    'test' => $this->testSummary($version),
                ];
            }),
            'mass_upgrade' => $this->massUpgradeSummary($app, $group),
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

    public function checkUpdates(Application $app)
    {
        (new CheckVersionUpdates($app->id))();

        return redirect('/admin/apps/'.$app->slug.'/versions')->with('success', __('admin.applications.versions.updates_checked'));
    }

    public function store(Request $request, Application $app)
    {
        /* Validate */
        $validatedData = $request->validate([
            'version' => ['required', 'not_regex:/\//', Rule::unique('app_versions', 'name')->where('application_id', $app->id)],
            'copy_from' => 'required|in:recommendations,previous_version,update,none',
            'copy_version' => 'exclude_unless:copy_from,previous_version,update|required|exists:app_versions,id',
            // The other images an update is adopted for
            'images' => 'exclude_unless:copy_from,update|nullable|array',
            'images.*' => 'string|max:64',
            'version_group_id' => ['nullable', 'integer', Rule::in($app->versionGroups()->pluck('app_version_groups.id'))],
        ]);
        $image_registry_id = null;
        $copy_version = null;
        $recommendations = [];
        if (in_array($validatedData['copy_from'], ['previous_version', 'update']) && is_numeric($validatedData['copy_version'])) {
            $copy_version = AppVersion::find($validatedData['copy_version']);
            $settings = $copy_version->settings;

            // Adopting an update: also move to the newest chart that passed the compatibility check
            if ($validatedData['copy_from'] === 'update' && $newer_chart = $copy_version->newerChartVersion()) {
                $settings['chart_version'] = $newer_chart;
            }
            $admin_path = $copy_version->admin_path;
            $roles = $copy_version->roles;
            $image_registry_id = $copy_version->image_registry_id;
        } elseif ($validatedData['copy_from'] === 'recommendations') {
            $profile = ApplicationFacade::profile($app);
            $recommendations = $profile->recommendations();
            $settings = $profile->recommendedVersionSettings();
            $admin_path = Arr::get($recommendations, 'admin_path', null);
            $roles = Arr::get($recommendations, 'roles', null);
        } else {
            $settings = null;
            $admin_path = null;
            $roles = null;
        }

        $app_version = new AppVersion;
        $app_version->application_id = $app->id;
        // Without one it joins the default group
        $app_version->version_group_id = $validatedData['version_group_id'] ?? null;
        $app_version->name = $request->input('version');
        $app_version->settings = $settings;
        $app_version->roles = $roles;
        $app_version->status = 'deactivated';
        $app_version->admin_path = $admin_path;
        $app_version->image_registry_id = $image_registry_id;
        $app_version->save();

        // The images besides the primary one, which follows the settings
        if ($copy_version) {
            $app_version->copyImagesFrom($copy_version);

            // Only the ones that were picked are moved to their newer tag
            if ($validatedData['copy_from'] === 'update') {
                $app_version->adoptImageUpdates($copy_version, $validatedData['images'] ?? []);
            }
        } elseif ($recommendations) {
            $app_version->addRecommendedImages($recommendations);
        }

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$validatedData['version'])->with('success', __('admin.applications.versions.added', ['version' => $app_version->name]));
    }

    public function edit(Application $app, AppVersion $version)
    {
        $announcements = Announcement::orderBy('created_at', 'desc')->limit(10)->get();
        $roles = $app->roles;

        return inertia()->render('Admin/Applications/Versions/VersionEdit', [
            'version' => [
                'id' => $version->id,
                'version' => $version->name,
                'helm_repo_name' => $version->helmRepo(),
                'image_repo_name' => $version->setting('image_repo_name'),
                'image_registry' => $version->setting('image_registry'),
                'image_registry_id' => $version->image_registry_id,
                'helm_repo_secret_id' => $version->chartRepoSecretId(),
                'chart_version' => $version->setting('chart_version'),
                'chart_name' => $version->chartName(),
                // The images besides the one these settings describe
                'extra_images' => $version->images()->where('role', '!=', 'primary')->count(),
                'version_group_id' => $version->version_group_id,
                'port' => $version->setting('port'),
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
                'test' => $this->testSummary($version),
                'is_active' => $version->status == 'active',
            ],
            'mass_upgrade' => $this->massUpgradeSummary($app, $version->versionGroup),
            'groups' => $this->groupOptions($app, $version->chart()),
            'app' => [
                'slug' => $app->slug,
                'name' => $app->name,
                'id' => $app->id,
            ],
            'image_registries' => RepoSecret::whereIn('type', [RepoSecret::TYPE_IMAGE, RepoSecret::TYPE_BOTH])->orderBy('name')->get()->map(function ($image_registry) {
                return [
                    'id' => $image_registry->id,
                    'name' => $image_registry->name,
                    'registry' => $image_registry->registry,
                ];
            }),
            'helm_repo_secrets' => RepoSecret::whereIn('type', [RepoSecret::TYPE_HELM, RepoSecret::TYPE_BOTH])->orderBy('name')->get()->map(function ($helm_repo_secret) {
                return [
                    'id' => $helm_repo_secret->id,
                    'name' => $helm_repo_secret->name,
                    'registry' => $helm_repo_secret->registry,
                ];
            }),
            'recommendations' => $this->recommendationSummary(ApplicationFacade::profile($app)->recommendations()),
            'announcements' => $announcements->count() > 0 ? $announcements->map(function ($announcement) {
                return [
                    'value' => $announcement->id,
                    'text' => $announcement->title,
                ];
            }) : [],
            'roles' => $roles->map(function ($role) {
                return [
                    'text' => $role->label,
                    'value' => $role->id,
                ];
            }),
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

    /**
     * The profile's recommendations as name/value rows. The 'images' entry holds
     * a list of component images, shown one row per image.
     *
     * @param  array<string, mixed>  $recommendations
     */
    private function recommendationSummary(array $recommendations): Collection
    {
        $images = Arr::pull($recommendations, 'images', []);

        $rows = collect($recommendations)
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value, $key) => [
                'name' => Str::headline($key),
                'value' => (string) $value,
            ]);

        foreach ((array) $images as $image) {
            $reference = trim(Arr::get($image, 'registry', '').'/'.Arr::get($image, 'repository', ''), '/');

            if ($tag = Arr::get($image, 'tag')) {
                $reference .= ':'.$tag;
            }

            $rows->push([
                'name' => Arr::get($image, 'label') ?? Str::headline(Arr::get($image, 'key', 'image')),
                'value' => $reference,
            ]);
        }

        return $rows->values();
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
            'chart_name' => 'string|nullable',
            'helm_repo_name' => 'string|nullable',
            'image_repo_name' => 'string|nullable',
            'port' => 'nullable|integer|min:1|max:65535',
            // Only within the version's own chart, and only while it is not the one enabled
            'version_group_id' => ['nullable', 'integer', Rule::in($version->chart()?->versionGroups()->pluck('id') ?? [])],
            'image_registry_id' => 'required|integer|exists:repo_secrets,id',
            'helm_repo_secret_id' => 'nullable|integer|exists:repo_secrets,id',
            'announcement_location' => 'required|in:none,remote,local',
            'announcement_id' => 'required_if:announcement_location,local|exists:announcements,id|nullable',
            'announcement_url' => 'required_if:announcement_location,remote|url:http,https|nullable',
        ]);

        $roles = $version->roles;

        $roles['default_admin_groups'] = $request->default_admin_roles;
        $roles['default_user_groups'] = $request->default_user_roles;

        if (($validatedData['version_group_id'] ?? null) && (int) $validatedData['version_group_id'] !== $version->version_group_id) {
            if ($version->status === 'active') {
                return back()->withErrors(['version_group_id' => __('admin.applications.versions.group_move_enabled')]);
            }

            $version->version_group_id = (int) $validatedData['version_group_id'];
        }

        $version->name = $request->input('version');
        $version->roles = $roles;
        $version->admin_path = $request->admin_path;
        $version->image_registry_id = $validatedData['image_registry_id'];
        $version->helm_repo_secret_id = $request->input('helm_repo_secret_id') ?: null;

        // Where the chart comes from belongs to the chart, so it changes for all of its versions.
        // The version keeps a copy until nothing reads it from there.
        $version->chart()?->update([
            'chart_name' => $request->input('chart_name') ?: null,
            'helm_repo' => $request->input('helm_repo_name') ?: null,
            'helm_repo_secret_id' => $version->helm_repo_secret_id,
        ]);

        $version->updateSettings([
            'chart_version' => $request->input('chart_version'),
            'helm_repo_name' => $request->input('helm_repo_name'),
            'image_repo_name' => $request->input('image_repo_name'),
            'chart_name' => $request->input('chart_name'),
            'port' => $request->input('port'),
        ]);
        $version->announcement_location = $request->input('announcement_location');
        $version->announcement_id = $request->input('announcement_id');
        $version->announcement_url = $request->input('announcement_url');
        $version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$request->input('version'))->with('success', __('admin.applications.versions.updated', ['version' => $version->name]));
    }

    public function enable(Application $app, AppVersion $version)
    {
        if (! $version->canBeEnabled()) {
            return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name)->with('error', __('admin.version_tests.errors.not_tested', ['version' => $version->name]));
        }

        // One version is enabled per group, so the others of its group make way for it
        $versions = AppVersion::where('application_id', $app->id)
            ->when($version->version_group_id, fn ($query) => $query->where('version_group_id', $version->version_group_id))
            ->get();

        foreach ($versions as $deactivated_version) {
            $deactivated_version->status = 'deactivated';
            $deactivated_version->save();
        }

        $version->status = 'active';
        $version->save();

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name)->with('success', __('admin.applications.versions.enabled', ['version' => $version->name]));
    }

    /**
     * Upgrade every install that is behind the enabled version.
     */
    public function massUpgrade(Application $app, AppVersion $version)
    {
        $redirect = redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name);

        if ($version->status !== 'active') {
            return $redirect->with('error', __('admin.applications.versions.mass_upgrade_not_enabled'));
        }

        if ($app->instancesBehind($version)->isEmpty()) {
            return $redirect->with('error', __('admin.applications.versions.mass_upgrade_none'));
        }

        Action::execute(new MassApplicationUpgrade($version));

        return $redirect->with('success', __('admin.applications.versions.mass_upgrade_started', ['version' => $version->name]));
    }

    public function disable(Application $app, AppVersion $version)
    {
        $version->status = 'deactivated';
        $version->save();

        // The app stays on while another group still has an enabled version
        if (! $app->versions()->where('status', 'active')->exists()) {
            $app->enabled = false;
            $app->save();
        }

        return redirect('/admin/apps/'.$app->slug.'/versions/'.$version->name)->with('success', __('admin.applications.versions.disabled', ['version' => $version->name]));
    }

    public function roles(Application $app, AppVersion $version)
    {
        $version_roles = $version->roles ?? [];
        $available_roles = [];

        if ($version_roles && array_key_exists('order', $version_roles) && count($version_roles['order']) > 0) {
            $ids = array_map('intval', $version_roles['order']);
            $cases = implode(' ', array_map(fn ($id, $i) => "WHEN {$id} THEN {$i}", $ids, array_keys($ids)));
            $available_roles = $app->roles()->orderByRaw("CASE id {$cases} ELSE 9999 END")->get();
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

    /**
     * Where a version stands with its tests, for the version pages.
     *
     * @return array<string, mixed>
     */
    private function testSummary(AppVersion $version): array
    {
        $run = $version->testRuns()->first();

        return [
            'gate' => $version->testGate(),
            'can_bypass' => $version->canBypassTests(),
            'run_id' => $run?->id,
            'can_enable' => $version->canBeEnabled(),
            'bypassed_by' => $run?->bypassed_by?->name,
            'bypass_reason' => $run?->bypass_reason,
        ];
    }

    /**
     * The mass upgrade button's state: only an enabled version with installs behind it has one.
     *
     * @return array<string, mixed>
     */
    private function massUpgradeSummary(Application $app, ?AppVersionGroup $group = null): array
    {
        $active = $group ? $group->activeVersion : $app->active_version();

        return [
            'version' => $active?->name,
            'count' => $active ? $app->instancesBehind($active)->count() : 0,
        ];
    }

    /**
     * The groups of the app for the pickers of the version pages, those of the
     * given chart only when one is.
     *
     * @return Collection<int, array{id: int, name: string, is_default: bool, versions: int, plans: int}>
     */
    private function groupOptions(Application $app, ?AppChart $chart = null): Collection
    {
        $groups = $app->versionGroups()->with('chart')->withCount(['versions', 'plans'])->orderBy('app_charts.id')->orderBy('app_version_groups.id')->get();
        $several_charts = $groups->pluck('app_chart_id')->unique()->count() > 1;

        return $groups
            ->when($chart, fn ($groups) => $groups->where('app_chart_id', $chart->id))
            ->map(fn (AppVersionGroup $group) => [
                'id' => $group->id,
                'name' => $several_charts ? $group->chart->name.' / '.$group->name : $group->name,
                'is_default' => $group->isAppDefault(),
                'versions' => $group->versions_count,
                'plans' => $group->plans_count,
            ])
            ->values();
    }
}
