<?php

namespace App\Updates\Detection;

use App\DefinitionChange;
use App\Jobs\Updates\ScanDriftForChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Notes an edit to a definition that app instances depend on, and starts
 * looking at the instances it can affect for what has gone out of date.
 *
 * Nothing is recorded, or looked at, for an edit that only changed cosmetic
 * attributes or that no instance uses.
 */
class DefinitionChanges
{
    public function __construct(private readonly ImpactResolver $impact) {}

    /**
     * @param  array<int, string>  $changed  The attributes that changed
     */
    public function record(Model $subject, string $action, array $changed): ?DefinitionChange
    {
        $relevant = $this->impact->relevant($subject::class, $changed);

        if ($relevant === []) {
            return null;
        }

        $application_id = $subject->application_id ?? null;
        $instances = $this->impact->targets($subject::class, $subject->getKey(), $application_id)?->count() ?? 0;
        $organizations = $this->impact->organizations($subject::class, $subject->getKey())?->count() ?? 0;

        if ($instances === 0 && $organizations === 0) {
            return null;
        }

        $change = DefinitionChange::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'subject_label' => $this->label($subject),
            'application_id' => $application_id,
            'action' => $action,
            'changed' => $relevant,
            'user_id' => auth()->id(),
        ]);

        ScanDriftForChange::dispatch($change->id)->afterCommit();

        $this->tellAdmin($change, $instances, $organizations);

        return $change;
    }

    /**
     * When an admin made the edit in the control panel, says how many instances or
     * organizations use what was edited, so it isn't a surprise that they are being
     * looked at.
     */
    private function tellAdmin(DefinitionChange $change, int $instances, int $organizations): void
    {
        if (! request()->hasSession() || ! Gate::allows('admin')) {
            return;
        }

        session()->flash('warning', $organizations > 0
            ? __('admin.updates.edit_impact_organizations', ['subject' => $change->subject_label, 'count' => $organizations])
            : __('admin.updates.edit_impact', ['subject' => $change->subject_label, 'count' => $instances]));
    }

    private function label(Model $subject): string
    {
        $name = (string) ($subject->getAttribute('name') ?: $subject->getAttribute('slug'));
        $application = $subject->getRelationValue('application')?->name;

        return $application ? "{$application}: {$name}" : $name;
    }
}
