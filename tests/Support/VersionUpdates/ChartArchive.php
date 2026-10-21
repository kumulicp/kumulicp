<?php

namespace Tests\Support\VersionUpdates;

use Phar;
use PharData;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds packaged charts (.tgz bytes) and repo indexes for tests.
 */
class ChartArchive
{
    public static function make(string $name, string $version, array $values = [], array $metadata = [], ?array $schema = null): string
    {
        $base = sys_get_temp_dir().'/chart-test-'.uniqid();
        $archive = new PharData($base.'.tar');

        $archive->addFromString("{$name}/Chart.yaml", Yaml::dump(array_merge([
            'apiVersion' => 'v2',
            'name' => $name,
            'version' => $version,
        ], $metadata), 4));
        $archive->addFromString("{$name}/values.yaml", Yaml::dump($values, 10));
        // Subchart files must not be mistaken for the parent's
        $archive->addFromString("{$name}/charts/sub/values.yaml", Yaml::dump(['unrelated' => true]));

        if ($schema !== null) {
            $archive->addFromString("{$name}/values.schema.json", json_encode($schema));
        }

        $archive->compress(Phar::GZ);
        $contents = file_get_contents($base.'.tar.gz');

        unset($archive);
        @unlink($base.'.tar');
        @unlink($base.'.tar.gz');

        return $contents;
    }

    public static function index(string $name, array $versions): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'entries' => [
                $name => array_map(fn ($version) => [
                    'name' => $name,
                    'version' => $version,
                    'urls' => ["{$name}-{$version}.tgz"],
                ], $versions),
            ],
        ], 6);
    }
}
