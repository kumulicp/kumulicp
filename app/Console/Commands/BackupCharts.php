<?php

namespace App\Console\Commands;

use App\AppInstance;
use App\Services\AppInstanceService;
use App\Support\ChartArchiveLayout;
use App\Support\CoreVersion;
use App\Support\SafeErrorMessage;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZipArchive;

/**
 * A record of the Helm values every app was given: one zip of YAML files on the backups disk, to look at
 * before a change that should leave them as they are, and again after it (see docs/volumes-and-settings-plan.md).
 * Each chart has one file, `<chart>.yaml`, which is what a release is given and what the app's Helm values
 * page shows.
 *
 * It does not change anything, and the secrets are masked as they are on the page. It is a record of
 * rendered values, not something to restore from: that is what `backup:run` is for.
 */
class BackupCharts extends Command
{
    protected $signature = 'backup:charts';

    protected $description = 'Write the Helm values of every app to a zip of YAML files, secrets masked (a record of what is deployed, not something to restore from)';

    public function handle(): int
    {
        $archive = tempnam(sys_get_temp_dir(), 'charts');
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::OVERWRITE);

        $files = [];
        $failures = [];
        $layout = new ChartArchiveLayout;
        $instances = 0;

        foreach (ChartArchiveLayout::instances()->lazyById() as $app_instance) {
            if (! ChartArchiveLayout::covers($app_instance)) {
                continue;
            }

            $instances++;

            try {
                $charts = (new AppInstanceService($app_instance))->renderedCharts();
            } catch (Throwable $exception) {
                report($exception);

                $failures[] = $this->describe($app_instance) + ['message' => SafeErrorMessage::from($exception)];
                $this->components->warn("Could not render {$app_instance->label} ({$app_instance->organization->name}): ".SafeErrorMessage::from($exception));

                continue;
            }

            $folder = $layout->folder($app_instance);

            foreach ($charts as $chart) {
                $path = ChartArchiveLayout::file($folder, $chart['name']);
                $yaml = Yaml::dump($chart['values'], 10, 2);

                $zip->addFromString($path, $yaml);

                $files[] = $this->describe($app_instance) + [
                    'path' => $path,
                    'chart' => $chart['name'],
                    'sha256' => hash('sha256', $yaml),
                ];
            }
        }

        if ($instances === 0) {
            $zip->close();
            @unlink($archive);
            $this->components->warn('There are no app instances to write.');

            return self::SUCCESS;
        }

        $zip->addFromString('manifest.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'core_version' => CoreVersion::current(),
            'instances' => $instances,
            'files' => $files,
            'failures' => $failures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $disk = Storage::disk(config('filesystems.app_backup'));
        $stored = $disk->putFileAs(ChartArchiveLayout::FOLDER, new File($archive), 'chart-values-'.now()->format('Y-m-d-His').'.zip');
        @unlink($archive);

        $this->components->info(count($files).' files of '.($instances - count($failures))." of {$instances} app instances: ".$this->where($disk, $stored));

        if ($failures) {
            $this->components->error(count($failures).' app instances could not be rendered and are listed in the archive\'s manifest.json.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{instance_id: int, organization: string, application: string, version: string|null}
     */
    private function describe(AppInstance $app_instance): array
    {
        return [
            'instance_id' => $app_instance->id,
            'organization' => $app_instance->organization->slug,
            'application' => $app_instance->application->slug,
            'version' => $app_instance->version?->name,
        ];
    }

    private function where($disk, string $stored): string
    {
        try {
            return $disk->path($stored);
        } catch (Throwable) {
            return config('filesystems.app_backup').':'.$stored;
        }
    }
}
