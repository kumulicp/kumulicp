<?php

namespace App\Console\Calls;

use App\AppInstance;
use App\Jobs\Updates\ScanInstancesForDrift;
use App\Organization;

/**
 * Looks at every app instance for what has gone out of date. Edits to
 * definitions are looked at as they are made (see DefinitionChanges); this finds
 * what that missed, like changes made without going through the models.
 */
class ScanUpdateDrift
{
    public function __construct(private ?string $app_slug = null, private bool $now = false) {}

    public function __invoke(): void
    {
        AppInstance::query()
            ->whereIn('organization_id', Organization::notTest()->select('organizations.id'))
            ->when($this->app_slug, fn ($query, $slug) => $query->whereHas('application', fn ($application) => $application->where('slug', $slug)))
            ->orderBy('id')
            ->pluck('id')
            ->chunk(ScanInstancesForDrift::BATCH)
            ->each(function ($ids) {
                $this->now
                    ? ScanInstancesForDrift::dispatchSync($ids->values()->all())
                    : ScanInstancesForDrift::dispatch($ids->values()->all());
            });
    }
}
