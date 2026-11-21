<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Every app that has versions gets the chart(s) they were already using and
     * one default group per chart holding those versions. Nothing about how apps
     * are deployed changes: the versions keep their own chart settings, which
     * remain what the app is deployed from.
     *
     * Versions of one app that name different charts or repositories get a chart
     * each, so the difference is visible instead of being merged away. The chart
     * of the enabled version (else the newest) is the default one.
     */
    public function up(): void
    {
        DB::table('applications')->orderBy('id')->chunkById(100, function ($applications) {
            foreach ($applications as $application) {
                if (DB::table('app_charts')->where('application_id', $application->id)->exists()) {
                    continue;
                }

                $this->backfill($application);
            }
        });
    }

    public function down(): void
    {
        DB::table('app_versions')->update(['version_group_id' => null]);
        DB::table('app_plans')->update(['version_group_id' => null]);
        DB::table('app_version_groups')->delete();
        DB::table('app_charts')->delete();
    }

    private function backfill(object $application): void
    {
        // The enabled version first, then the newest
        $versions = DB::table('app_versions')
            ->where('application_id', $application->id)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        if ($versions->isEmpty()) {
            return;
        }

        $by_identity = $versions->groupBy(fn (object $version) => $this->identity($version)['key']);

        // Versions that name no chart at all join the default one
        $default_key = $this->identity($versions->first())['key'];
        $nameless = $by_identity->pull('|', collect());
        $by_identity->put($default_key, ($by_identity->get($default_key) ?? collect())->concat($nameless));

        $used_slugs = [];

        foreach ($by_identity as $key => $chart_versions) {
            $identity = $this->identity($chart_versions->first());
            $is_default = $key === $default_key;

            $chart_id = DB::table('app_charts')->insertGetId([
                'application_id' => $application->id,
                'name' => $is_default ? $this->defaultName($application) : $this->chartName($identity),
                'slug' => $this->uniqueSlug($is_default ? 'default' : ($identity['chart_name'] ?: 'chart'), $used_slugs),
                'chart_name' => $identity['chart_name'],
                'helm_repo' => $identity['helm_repo'],
                'helm_repo_secret_id' => $chart_versions->first()->helm_repo_secret_id,
                'chart_source_id' => $is_default ? $application->chart_source_id : null,
                'is_default' => $is_default,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $group_id = DB::table('app_version_groups')->insertGetId([
                'app_chart_id' => $chart_id,
                'name' => 'Default',
                'slug' => 'default',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('app_versions')->whereIn('id', $chart_versions->pluck('id'))->update(['version_group_id' => $group_id]);
        }
    }

    /**
     * @return array{key: string, chart_name: string|null, helm_repo: string|null}
     */
    private function identity(object $version): array
    {
        $settings = json_decode($version->settings ?? '', true);
        $settings = is_array($settings) ? $settings : [];

        $chart_name = $this->text($settings['chart_name'] ?? null);
        $helm_repo = $this->text($settings['helm_repo_name'] ?? null);

        return ['key' => $chart_name.'|'.$helm_repo, 'chart_name' => $chart_name, 'helm_repo' => $helm_repo];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function defaultName(object $application): string
    {
        $source = $application->chart_source_id ? DB::table('chart_sources')->where('id', $application->chart_source_id)->value('name') : null;

        return $source ?? 'Default';
    }

    /**
     * @param  array{chart_name: string|null, helm_repo: string|null}  $identity
     */
    private function chartName(array $identity): string
    {
        $host = $identity['helm_repo'] ? parse_url($identity['helm_repo'], PHP_URL_HOST) : null;

        return trim(($identity['chart_name'] ?? 'Chart').' '.($host ? "({$host})" : ''));
    }

    /**
     * @param  array<int, string>  $used
     */
    private function uniqueSlug(string $base, array &$used): string
    {
        $slug = Str::slug($base) ?: 'chart';
        $candidate = $slug;

        for ($n = 2; in_array($candidate, $used, true); $n++) {
            $candidate = "{$slug}-{$n}";
        }

        return $used[] = $candidate;
    }
};
