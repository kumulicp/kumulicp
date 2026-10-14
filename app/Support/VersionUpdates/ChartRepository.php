<?php

namespace App\Support\VersionUpdates;

/**
 * Somewhere a chart's versions can be listed and its packages downloaded
 * without the helm binary.
 */
interface ChartRepository
{
    /**
     * Every published version of the chart, keyed by version, with whatever
     * index metadata the repository provides (kubeVersion, annotations).
     *
     * @return array<string, array>
     */
    public function versions(): array;

    // The packaged chart (.tgz bytes) for one version.
    public function package(string $version): string;
}
