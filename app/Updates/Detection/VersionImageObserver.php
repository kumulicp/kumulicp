<?php

namespace App\Updates\Detection;

use App\AppVersion;
use App\AppVersionImage;

/**
 * Notes an edit to one of a version's images as an edit to the version, which is
 * what the instances running it depend on (see DefinitionChanges).
 *
 * Nothing is noted for what is only about how an image is shown or checked for
 * updates, or for the primary image that follows the version's own settings: its
 * changes are the version's, and are noted as such.
 */
class VersionImageObserver
{
    public function __construct(
        private readonly DefinitionChanges $changes,
        private readonly ImpactResolver $impact,
    ) {}

    public function saved(AppVersionImage $image): void
    {
        // A row that was just inserted has no changes, only attributes
        $changed = $image->wasRecentlyCreated ? array_keys($image->getAttributes()) : array_keys($image->getChanges());

        if ($this->impact->relevantImageAttributes($changed) !== []) {
            $this->record($image);
        }
    }

    public function deleted(AppVersionImage $image): void
    {
        $this->record($image);
    }

    private function record(AppVersionImage $image): void
    {
        if ($image->tag_from_version_name) {
            return;
        }

        // Looked up rather than taken from the relation: when an image is deleted its version may be gone too
        $version = AppVersion::find($image->app_version_id);

        if ($version) {
            $this->changes->record($version, 'updated', ['images']);
        }
    }
}
