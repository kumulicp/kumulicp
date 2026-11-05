<?php

namespace App\Support;

use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use App\Services\AppInstanceService;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renders the Helm values an app's charts would produce for a plan whose
 * configurations have been edited but not saved, so the plan editor can show a
 * live example.
 *
 * Charts are built from a real app instance (organization, domain, servers,
 * version), so the example borrows one: a subscriber of the plan when it has
 * any, otherwise any other live instance of the app. The plan the instance
 * sees is swapped for an in-memory copy carrying the unsaved values, and the
 * whole render runs in a transaction that is always rolled back, because chart
 * builders persist small bookkeeping settings.
 */
class PlanValuesPreview
{
    public const NO_INSTANCE = 'no_instance';

    public const FAILED = 'failed';

    public const OK = 'ok';

    /**
     * @param  array<string, mixed>  $configurations  the form's configuration values, keyed by name
     * @param  array<string, array<string, mixed>>  $additionalConfigs  configs added to the plan, keyed by name
     * @return array{status: string, charts: array<int, array{name: string, values: mixed}>, instance: array{label: string, organization: string}|null}
     */
    public function render(Application $app, AppPlan $plan, array $configurations, array $additionalConfigs = []): array
    {
        $instance = $this->instance($app, $plan);

        if (! $instance) {
            return ['status' => self::NO_INSTANCE, 'charts' => [], 'instance' => null];
        }

        $source = [
            'label' => $instance->label,
            'organization' => $instance->organization?->name ?? '',
        ];

        DB::beginTransaction();

        try {
            $preview_plan = $this->previewPlan($app, $plan, $configurations, $additionalConfigs);

            $instance->setRelation('plan', $preview_plan);
            // A persistent or overridden value on the instance wins over the
            // plan's, which would hide the very edit being previewed.
            $instance->settings = $this->withoutInstanceConfigurations($instance->settings ?? [], $preview_plan);

            $charts = collect((new AppInstanceService($instance))->charts())
                ->filter(fn ($chart) => $chart instanceof HelmChart)
                ->map(fn (HelmChart $chart) => [
                    'name' => $chart->chartName(),
                    'values' => Redactor::redact($chart->valuesWithAdditionalConfigs()),
                ])
                ->values()
                ->all();

            return ['status' => self::OK, 'charts' => $charts, 'instance' => $source];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => self::FAILED, 'charts' => [], 'instance' => $source];
        } finally {
            DB::rollBack();
        }
    }

    private function instance(Application $app, AppPlan $plan): ?AppInstance
    {
        return AppInstance::query()
            ->where('application_id', $app->id)
            ->notDeactivated()
            ->with('organization')
            ->orderByRaw('plan_id = ? desc', [$plan->id])
            ->latest('id')
            ->first();
    }

    /**
     * An unsaved copy of the plan with its configurations replaced by the
     * form's, processed the same way saving would.
     */
    private function previewPlan(Application $app, AppPlan $plan, array $configurations, array $additionalConfigs): AppPlan
    {
        $preview = clone $plan;

        // Same rule as saving: a config only counts while the form still has a value for it.
        $additional = [];
        foreach (array_merge($plan->additionalConfigs(), $additionalConfigs) as $config) {
            if (is_array($config) && isset($config['name'], $config['type']) && array_key_exists($config['name'], $configurations)) {
                $additional[$config['name']] = Arr::only($config, ['name', 'type', 'persistent', 'options']);
            }
        }

        $profile_names = array_keys(ApplicationFacade::profile($app)->configurations());
        $known = array_merge($profile_names, array_keys($additional));

        // Profile configs the form left out keep their saved value; removed additional configs drop out, as they do on save.
        $processed = ApplicationFacade::processConfigurations(
            $app,
            $preview,
            array_merge(Arr::only($plan->setting('configurations', []), $profile_names), Arr::only($configurations, $known)),
        );

        $preview->updateSettings([
            'configurations' => $processed,
            'additionalConfigs' => $additional,
        ]);

        return $preview;
    }

    private function withoutInstanceConfigurations(array $settings, AppPlan $plan): array
    {
        unset($settings['configurations']);

        foreach (array_keys($plan->setting('configurations', [])) as $name) {
            unset($settings['override'][$name]);
        }

        return $settings;
    }
}
