<?php

namespace App\Updates\Detection;

use Illuminate\Database\Eloquent\Model;

/** Watches the definitions app instances depend on (see ImpactResolver::watched()) */
class DefinitionObserver
{
    public function __construct(private readonly DefinitionChanges $changes) {}

    public function saved(Model $model): void
    {
        // A model that was just inserted has no changes, only attributes
        $changed = $model->wasRecentlyCreated ? array_keys($model->getAttributes()) : array_keys($model->getChanges());

        $this->changes->record($model, $model->wasRecentlyCreated ? 'created' : 'updated', $changed);
    }

    public function deleted(Model $model): void
    {
        $this->changes->record($model, 'deleted', ['*']);
    }
}
