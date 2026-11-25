<?php

namespace App\Http\Controllers\Admin\Applications;

use App\AppVersionGroup;
use App\Application;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VersionGroups extends Controller
{
    public function store(Request $request, Application $app)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // A new group is made for the default chart, the one the app is deployed from
        $chart = $app->charts()->where('is_default', true)->first();

        if (! $chart) {
            return back()->withErrors(['name' => __('admin.applications.version_groups.no_chart')]);
        }

        $group = $chart->versionGroups()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($chart->versionGroups()->pluck('slug')->all(), $validated['name']),
        ]);

        return redirect('/admin/apps/'.$app->slug.'/versions?group='.$group->id)->with('success', __('admin.applications.version_groups.added', ['group' => $group->name]));
    }

    public function update(Request $request, Application $app, AppVersionGroup $group)
    {
        $this->belongsToApp($app, $group);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // The slug stays: nothing is addressed by it, but renaming should not look like a new group
        $group->update(['name' => $validated['name']]);

        return redirect('/admin/apps/'.$app->slug.'/versions?group='.$group->id)->with('success', __('admin.applications.version_groups.renamed', ['group' => $group->name]));
    }

    public function destroy(Application $app, AppVersionGroup $group)
    {
        $this->belongsToApp($app, $group);

        $back = redirect('/admin/apps/'.$app->slug.'/versions?group='.$group->id);

        if ($group->isAppDefault()) {
            return $back->with('error', __('admin.applications.version_groups.default'));
        }

        if ($group->versions()->exists() || $group->plans()->exists()) {
            return $back->with('error', __('admin.applications.version_groups.in_use'));
        }

        $group->delete();

        return redirect('/admin/apps/'.$app->slug.'/versions')->with('success', __('admin.applications.version_groups.deleted', ['group' => $group->name]));
    }

    private function belongsToApp(Application $app, AppVersionGroup $group): void
    {
        abort_unless($group->chart->application_id === $app->id, 404);
    }

    /**
     * @param  array<int, string>  $taken
     */
    private function uniqueSlug(array $taken, string $name): string
    {
        $slug = Str::slug($name) ?: 'group';
        $candidate = $slug;

        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$slug}-{$n}";
        }

        return $candidate;
    }
}
