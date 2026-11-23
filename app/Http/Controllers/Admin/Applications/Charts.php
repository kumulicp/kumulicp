<?php

namespace App\Http\Controllers\Admin\Applications;

use App\AppChart;
use App\Application;
use App\ChartSource;
use App\Enums\ApplicationSource;
use App\Http\Controllers\Controller;
use App\Support\Catalog\ChartCatalogSync;
use App\Support\SafeErrorMessage;
use Illuminate\Http\Request;
use Throwable;

class Charts extends Controller
{
    public function index(Application $app)
    {
        $charts = $app->charts()->with(['chartSource', 'versionGroups' => fn ($groups) => $groups->withCount(['versions', 'plans'])])->orderByDesc('is_default')->orderBy('id')->get();

        return inertia()->render('Admin/Applications/Charts/ChartsList', [
            'app' => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
            ],
            'charts' => $charts->map(fn (AppChart $chart) => [
                'id' => $chart->id,
                'name' => $chart->name,
                'chart_name' => $chart->chart_name,
                'helm_repo' => $chart->helm_repo,
                'source' => $chart->source->label(),
                'is_default' => $chart->is_default,
                'enabled' => $chart->enabled,
                'groups' => $chart->versionGroups->count(),
                'versions' => $chart->versionGroups->sum('versions_count'),
                'plans' => $chart->versionGroups->sum('plans_count'),
            ])->values(),
            'sources' => $this->attachableSources($app, $charts),
            'breadcrumbs' => [
                ['url' => '/admin/apps', 'label' => __('admin.applications.apps')],
                ['label' => $app->name, 'url' => '/admin/apps/'.$app->slug],
                ['label' => __('admin.applications.charts.charts')],
            ],
        ]);
    }

    /**
     * Adds a chart of a chart source to the app (see ChartCatalogSync::attach()).
     */
    public function attach(Request $request, Application $app, ChartCatalogSync $sync)
    {
        $validated = $request->validate([
            'chart_source_id' => 'required|integer|exists:chart_sources,id',
            'chart_name' => 'nullable|string|max:100',
        ]);

        $source = ChartSource::findOrFail($validated['chart_source_id']);

        try {
            $result = $sync->attach($source, $app, $validated['chart_name'] ?? null);
        } catch (Throwable $e) {
            return redirect('/admin/apps/'.$app->slug.'/charts')->with('error', SafeErrorMessage::from($e));
        }

        return redirect('/admin/apps/'.$app->slug.'/charts')->with('success', __($result['created'] ? 'admin.applications.charts.added' : 'admin.applications.charts.refreshed', ['chart' => $result['chart']->name]));
    }

    public function enable(Application $app, AppChart $chart)
    {
        abort_unless($chart->application_id === $app->id, 404);

        $chart->update(['enabled' => true]);

        return redirect('/admin/apps/'.$app->slug.'/charts')->with('success', __('admin.applications.charts.enabled', ['chart' => $chart->name]));
    }

    public function disable(Application $app, AppChart $chart)
    {
        abort_unless($chart->application_id === $app->id, 404);

        $back = redirect('/admin/apps/'.$app->slug.'/charts');

        if ($chart->is_default) {
            return $back->with('error', __('admin.applications.charts.default'));
        }

        // Plans and installs that use it keep it: it is only no longer offered to other plans
        if ($chart->versionGroups()->whereHas('plans')->exists()) {
            return $back->with('error', __('admin.applications.charts.in_use'));
        }

        $chart->update(['enabled' => false]);

        return $back->with('success', __('admin.applications.charts.disabled', ['chart' => $chart->name]));
    }

    /**
     * The chart sources that have a chart of this app's name still to be added.
     *
     * @param  \Illuminate\Support\Collection<int, AppChart>  $charts
     * @return array<int, array{id: int, name: string}>
     */
    private function attachableSources(Application $app, $charts): array
    {
        return ChartSource::where('enabled', true)->orderBy('name')->get()
            ->filter(fn (ChartSource $source) => ApplicationSource::forChartSource($source) === ApplicationSource::HelmForge)
            ->reject(fn (ChartSource $source) => $app->chart_source_id === $source->id && $app->isHelmForge())
            ->reject(fn (ChartSource $source) => $charts->contains(fn (AppChart $chart) => ! $chart->is_default && $chart->chart_source_id === $source->id))
            ->map(fn (ChartSource $source) => ['id' => $source->id, 'name' => $source->name])
            ->values()
            ->all();
    }
}
