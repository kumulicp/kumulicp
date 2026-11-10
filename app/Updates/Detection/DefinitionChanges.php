<?php

namespace App\Updates\Detection;

use App\DefinitionChange;
use App\Jobs\Updates\ScanDriftForChange;
use Illuminate\Database\Eloquent\Model;

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
        $targets = $this->impact->targets($subject::class, $subject->getKey(), $application_id);

        if (! $targets || ! $targets->exists()) {
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

        return $change;
    }

    private function label(Model $subject): string
    {
        $name = (string) ($subject->getAttribute('name') ?: $subject->getAttribute('slug'));
        $application = $subject->application?->name;

        return $application ? "{$application}: {$name}" : $name;
    }
}
