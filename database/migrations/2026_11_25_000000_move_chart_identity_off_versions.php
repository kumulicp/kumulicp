<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Where a version is deployed from is its chart's. A chart that still lacks any of it
     * takes it from the versions in its groups (the enabled one first, then the newest),
     * and the versions then drop their own copy of the chart's name and repository.
     *
     * A version that is in no group has no chart to take over, so it keeps what it has.
     */
    public function up(): void
    {
        DB::table('app_charts')->orderBy('id')->chunkById(100, function ($charts) {
            foreach ($charts as $chart) {
                $this->fill($chart);
            }
        });

        DB::table('app_versions')->whereNotNull('version_group_id')->orderBy('id')->chunkById(200, function ($versions) {
            foreach ($versions as $version) {
                $settings = json_decode($version->settings ?? '', true);

                if (! is_array($settings) || ! array_intersect_key($settings, array_flip(['chart_name', 'helm_repo_name']))) {
                    continue;
                }

                unset($settings['chart_name'], $settings['helm_repo_name']);

                DB::table('app_versions')->where('id', $version->id)->update(['settings' => json_encode($settings)]);
            }
        });
    }

    // What the versions had is the chart's now; there is nothing to put back where it was
    public function down(): void {}

    private function fill(object $chart): void
    {
        if ($chart->chart_name && $chart->helm_repo && $chart->helm_repo_secret_id) {
            return;
        }

        $versions = DB::table('app_versions')
            ->whereIn('version_group_id', DB::table('app_version_groups')->where('app_chart_id', $chart->id)->select('id'))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        $update = [];

        foreach ($versions as $version) {
            $settings = json_decode($version->settings ?? '', true);
            $settings = is_array($settings) ? $settings : [];

            foreach (['chart_name' => 'chart_name', 'helm_repo' => 'helm_repo_name'] as $column => $key) {
                $value = $settings[$key] ?? null;

                if (! $chart->$column && ! isset($update[$column]) && is_string($value) && trim($value) !== '') {
                    $update[$column] = trim($value);
                }
            }

            if (! $chart->helm_repo_secret_id && ! isset($update['helm_repo_secret_id']) && ($version->helm_repo_secret_id ?? null)) {
                $update['helm_repo_secret_id'] = $version->helm_repo_secret_id;
            }
        }

        if ($update) {
            DB::table('app_charts')->where('id', $chart->id)->update($update);
        }
    }
};
