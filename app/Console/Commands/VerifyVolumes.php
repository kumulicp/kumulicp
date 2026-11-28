<?php

namespace App\Console\Commands;

use App\Services\AppInstanceService;
use App\Support\ChartArchiveLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use ZipArchive;

/**
 * Renders the Helm values of every app again and compares them, key by key, with an archive `backup:charts`
 * wrote before a change that should have left them as they were (docs/volumes-and-settings-plan.md,
 * "Proof it changed nothing"). A difference is a stop: it says which app, which key, and what it was and is.
 *
 * It does not change anything: the values are rendered the way `backup:charts` does, in a transaction that
 * is rolled back. The secrets are masked in both, so a changed password is not seen.
 */
class VerifyVolumes extends Command
{
    protected $signature = 'volumes:verify
        {archive : An archive written by backup:charts: a path, or the name of one on the backups disk}
        {--ignore=* : A pattern for keys that are meant to differ, such as "redis.*.persistence.size" (* matches anything)}';

    protected $description = 'Render every app\'s Helm values again and compare them with an archive from backup:charts';

    // Said for a key that is not in one of the two
    private const ABSENT = '(not there)';

    public function handle(): int
    {
        if (! $path = $this->resolve((string) $this->argument('archive'))) {
            $this->components->error('There is no such archive, as a path or on the backups disk.');

            return self::FAILURE;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $this->components->error('That is not a zip archive.');

            return self::FAILURE;
        }

        [$before, $failed_before] = $this->read($zip);
        $zip->close();

        $layout = new ChartArchiveLayout;
        $ignore = (array) $this->option('ignore');
        $differences = 0;
        $compared = 0;
        $seen = [];

        foreach (ChartArchiveLayout::instances()->lazyById() as $app_instance) {
            if (! ChartArchiveLayout::covers($app_instance) || in_array($app_instance->id, $failed_before, true)) {
                continue;
            }

            try {
                $charts = (new AppInstanceService($app_instance))->renderedCharts();
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error("{$app_instance->label} ({$app_instance->organization->name}) can not be rendered now.");
                $differences++;

                continue;
            }

            $folder = $layout->folder($app_instance);

            foreach ($charts as $chart) {
                $file = ChartArchiveLayout::file($folder, $chart['name']);
                $seen[$file] = true;

                if (! array_key_exists($file, $before)) {
                    $this->components->twoColumnDetail($file, 'new since the archive');

                    continue;
                }

                $compared++;
                $differences += $this->compare($file, $before[$file], $this->roundTrip($chart['values']), $ignore);
            }
        }

        foreach (array_diff_key($before, $seen) as $file => $unused) {
            $this->components->error("{$file} is in the archive and is no longer rendered.");
            $differences++;
        }

        if ($differences > 0) {
            $this->components->error("{$differences} differences in {$compared} files compared.");

            return self::FAILURE;
        }

        $this->components->info("{$compared} files compared, and none differs.");

        return self::SUCCESS;
    }

    /**
     * The archive's files as values, by path, and the instances it says it could not render.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, int>}
     */
    private function read(ZipArchive $zip): array
    {
        $files = [];
        $failed = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if ($name === 'manifest.json') {
                $manifest = json_decode((string) $zip->getFromIndex($index), true);
                $failed = array_map('intval', array_column($manifest['failures'] ?? [], 'instance_id'));
            } elseif (str_ends_with($name, '.yaml')) {
                $files[$name] = (array) Yaml::parse((string) $zip->getFromIndex($index));
            }
        }

        return [$files, $failed];
    }

    /**
     * Says what differs in one file, and gives how many keys.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     * @param  array<int, string>  $ignore
     */
    private function compare(string $file, array $before, array $now, array $ignore): int
    {
        $was = $this->flatten($before);
        $is = $this->flatten($now);
        $lines = [];

        foreach (array_unique([...array_keys($was), ...array_keys($is)]) as $key) {
            if ($this->ignored($key, $ignore)) {
                continue;
            }

            $old = array_key_exists($key, $was) ? $was[$key] : self::ABSENT;
            $new = array_key_exists($key, $is) ? $is[$key] : self::ABSENT;

            // A class or a claim that is null, or is an empty text, is not set either way, and the chart gives the
            // release no class or no claim for either. Only a key that is there on both sides is not a difference
            if ($old !== $new && ! ($this->empty($old) && $this->empty($new))) {
                $lines[] = "  {$key}: ".$this->show($old).' -> '.$this->show($new);
            }
        }

        if ($lines) {
            $this->components->warn($file);

            foreach ($lines as $line) {
                $this->line($line);
            }
        }

        return count($lines);
    }

    private function empty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Every value by the path of its key, an empty list or map as one.
     *
     * @param  array<int|string, mixed>  $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== []) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    // The way it is in the archive, so that a number or a string is the same on both sides
    private function roundTrip(array $values): array
    {
        return (array) Yaml::parse(Yaml::dump($values, 10, 2));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function ignored(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function show(mixed $value): string
    {
        return is_string($value) && $value === self::ABSENT ? $value : mb_strimwidth((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 80, '...');
    }

    private function resolve(string $archive): ?string
    {
        if (is_file($archive)) {
            return $archive;
        }

        $disk = Storage::disk(config('filesystems.app_backup'));

        foreach ([$archive, ChartArchiveLayout::FOLDER.'/'.$archive] as $name) {
            if ($disk->exists($name)) {
                return $disk->path($name);
            }
        }

        return null;
    }
}
