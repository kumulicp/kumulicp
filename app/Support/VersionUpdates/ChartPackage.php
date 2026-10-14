<?php

namespace App\Support\VersionUpdates;

use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The parts of a packaged chart (.tgz) a compatibility check needs:
 * Chart.yaml, the default values.yaml and the optional values.schema.json.
 * Read with PharData, so no helm binary is involved.
 */
class ChartPackage
{
    public function __construct(
        public array $metadata,
        public array $values,
        public ?array $schema = null,
    ) {}

    public static function fromArchive(string $contents): self
    {
        $path = tempnam(sys_get_temp_dir(), 'chart');
        $archive_path = $path.'.tar.gz';
        rename($path, $archive_path);
        file_put_contents($archive_path, $contents);

        try {
            $files = self::topLevelFiles($archive_path, ['Chart.yaml', 'values.yaml', 'values.schema.json']);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('admin.version_updates.chart_unreadable', ['error' => $exception->getMessage()]), 0, $exception);
        } finally {
            @unlink($archive_path);
        }

        if (! isset($files['Chart.yaml'])) {
            throw new RuntimeException(__('admin.version_updates.chart_unreadable', ['error' => 'Chart.yaml not found']));
        }

        $schema = isset($files['values.schema.json']) ? json_decode($files['values.schema.json'], true) : null;

        return new self(
            (array) Yaml::parse($files['Chart.yaml']),
            isset($files['values.yaml']) ? (array) (Yaml::parse($files['values.yaml']) ?? []) : [],
            is_array($schema) ? $schema : null,
        );
    }

    public function version(): ?string
    {
        return isset($this->metadata['version']) ? (string) $this->metadata['version'] : null;
    }

    public function apiVersion(): ?string
    {
        return $this->metadata['apiVersion'] ?? null;
    }

    public function kubeVersion(): ?string
    {
        return $this->metadata['kubeVersion'] ?? null;
    }

    public function annotation(string $name): ?string
    {
        return $this->metadata['annotations'][$name] ?? null;
    }

    /**
     * Files directly inside the chart's top-level folder (<chart>/<file>),
     * ignoring subcharts under <chart>/charts/.
     *
     * @return array<string, string>
     */
    private static function topLevelFiles(string $archive_path, array $names): array
    {
        $archive = new PharData($archive_path);
        $prefix = 'phar://'.$archive_path.'/';
        $files = [];

        foreach (new RecursiveIteratorIterator($archive) as $file) {
            $relative = substr($file->getPathname(), strlen($prefix));
            $parts = explode('/', $relative);

            if (count($parts) === 2 && in_array($parts[1], $names, true)) {
                $files[$parts[1]] = file_get_contents($file->getPathname());
            }
        }

        unset($archive);

        return $files;
    }
}
