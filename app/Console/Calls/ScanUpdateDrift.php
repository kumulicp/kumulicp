<?php

namespace App\Console\Calls;

use App\AppInstance;
use App\Jobs\Updates\ScanInstancesForDrift;
use App\Jobs\Updates\ScanOrganizationsForDrift;
use App\Organization;
use App\Updates\OrganizationDrift;

/**
 * Looks at every app instance, and every organization's subscription, for what
 * has gone out of date. Edits to
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

        // An app's own instances are all that was asked for
        if ($this->app_slug) {
            return;
        }

        app(OrganizationDrift::class)->scannable()
            ->orderBy('id')
            ->pluck('id')
            ->chunk(ScanOrganizationsForDrift::BATCH)
            ->each(function ($ids) {
                $this->now
                    ? ScanOrganizationsForDrift::dispatchSync($ids->values()->all())
                    : ScanOrganizationsForDrift::dispatch($ids->values()->all());
            });
    }
}
