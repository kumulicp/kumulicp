<?php

namespace App\Integrations\ServerManagers\Kubernetes\Charts\Job;

/**
 * A job chart a RunJob task can store as plain values and build again, instead
 * of keeping the chart object itself in the database.
 */
interface RebuildableJobChart
{
    /**
     * What it takes to build the chart again: ids and scalars, never objects.
     *
     * @return array<string, int|string|null>
     */
    public function taskValues(): array;

    /**
     * @param  array<string, mixed>  $values  What taskValues() returned
     */
    public static function fromTaskValues(array $values): static;
}
