<?php

namespace App\Updates;

use App\AppInstance;
use App\Organization;
use App\UpdateState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What is out of date, as the admin pages show it: how much, why, and where.
 * Reads what UpdateDrift::refresh() stored, it works nothing out.
 *
 * Instances of test organizations are left out, as they are when looking for
 * what is out of date.
 */
class PendingUpdates
{
    public function __construct(private readonly AspectRegistry $aspects) {}

    /**
     * The aspects that were out of date the last time they were looked at.
     *
     * @return Builder<UpdateState>
     */
    public function query(): Builder
    {
        return UpdateState::drifted()->whereIn(
            'app_instance_id',
            AppInstance::query()->select('id')->whereIn('organization_id', Organization::notTest()->select('organizations.id'))
        );
    }

    /**
     * The same, narrowed to one edit ('none' for what was found without one) and/or one aspect.
     *
     * @param  array{change?: string|int|null, aspect?: string|null}  $filters
     * @return Builder<UpdateState>
     */
    public function filtered(array $filters): Builder
    {
        $change = $filters['change'] ?? null;

        return $this->query()
            ->when($change === 'none', fn (Builder $query) => $query->whereNull('definition_change_id'))
            ->when($change !== null && $change !== 'none', fn (Builder $query) => $query->where('definition_change_id', (int) $change))
            ->when($filters['aspect'] ?? null, fn (Builder $query, string $aspect) => $query->where('aspect', $aspect));
    }

    /** How many app instances are out of date for something */
    public function count(): int
    {
        return $this->query()->distinct()->count('app_instance_id');
    }

    /**
     * How much is out of date, and for what.
     *
     * @return array{instances: int, organizations: int, since: string|null, aspects: array<int, array{key: string, label: string, instances: int}>}
     */
    public function summary(): array
    {
        $instance_ids = $this->query()->distinct()->pluck('app_instance_id');

        $aspects = $this->query()
            ->select('aspect')
            ->selectRaw('count(distinct app_instance_id) as instances')
            ->groupBy('aspect')
            ->orderByDesc('instances')
            ->orderBy('aspect')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'key' => $row->aspect,
                'label' => $this->aspects->label($row->aspect),
                'instances' => (int) $row->instances,
            ])
            ->all();

        return [
            'instances' => $instance_ids->count(),
            'organizations' => $instance_ids->isEmpty() ? 0 : AppInstance::whereIn('id', $instance_ids)->distinct()->count('organization_id'),
            'since' => $this->query()->whereNotNull('drift_detected_at')->orderBy('drift_detected_at')->value('drift_detected_at')?->toIso8601String(),
            'aspects' => $aspects,
        ];
    }

    /**
     * What is out of date, by the edit that put it there. What was found without
     * seeing an edit (by the nightly scan, or an edit that bypassed the models) is
     * one group of its own, with a null change. The newest first.
     *
     * @return array<int, array{change: array<string, mixed>|null, instances: int, organizations: int, since: string|null, aspects: array<int, array{key: string, label: string, instances: int}>}>
     */
    public function groups(): array
    {
        $states = $this->query()
            ->with('change.user')
            ->get(['id', 'app_instance_id', 'aspect', 'definition_change_id', 'drift_detected_at']);

        $organizations = AppInstance::whereIn('id', $states->pluck('app_instance_id')->unique())->pluck('organization_id', 'id');

        return $states
            ->groupBy(fn (UpdateState $state) => $state->definition_change_id ?? 'none')
            ->map(function (Collection $group) use ($organizations) {
                $instances = $group->pluck('app_instance_id')->unique();

                return [
                    'change' => $this->change($group->first()),
                    'instances' => $instances->count(),
                    'organizations' => $instances->map(fn ($id) => $organizations[$id] ?? null)->filter()->unique()->count(),
                    'since' => $group->min('drift_detected_at')?->toIso8601String(),
                    'aspects' => $group->groupBy('aspect')
                        ->map(fn (Collection $states, string $aspect) => [
                            'key' => $aspect,
                            'label' => $this->aspects->label($aspect),
                            'instances' => $states->pluck('app_instance_id')->unique()->count(),
                        ])
                        ->sortBy('key')
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc(fn (array $group) => $group['since'] ?? '')
            ->values()
            ->all();
    }

    /**
     * The instances that are out of date, with what each is out of date for.
     *
     * @param  array{change?: string|int|null, aspect?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function instances(array $filters = [], int $per_page = 25): LengthAwarePaginator
    {
        $page = AppInstance::query()
            ->whereIn('id', $this->filtered($filters)->select('app_instance_id'))
            ->with(['organization:id,name', 'application:id,name,slug', 'version:id,name'])
            ->orderBy('organization_id')
            ->orderBy('id')
            ->paginate($per_page)
            ->withQueryString();

        $aspects = $this->byInstance($page->getCollection()->pluck('id')->all(), $filters);

        return $page->through(fn (AppInstance $instance) => [
            'id' => $instance->id,
            'label' => $instance->label,
            'application' => $instance->application?->name,
            'version' => $instance->version?->name,
            'organization' => ['id' => $instance->organization_id, 'name' => $instance->organization?->name],
            'aspects' => $aspects[$instance->id] ?? [],
        ]);
    }

    /**
     * What each of the instances is out of date for, and since when and why.
     *
     * @param  array<int, int>  $instance_ids
     * @param  array{change?: string|int|null, aspect?: string|null}  $filters
     * @return array<int, array<int, array{key: string, label: string, since: string|null, change: array<string, mixed>|null}>>
     */
    public function byInstance(array $instance_ids, array $filters = []): array
    {
        if ($instance_ids === []) {
            return [];
        }

        return $this->filtered($filters)
            ->whereIn('app_instance_id', $instance_ids)
            ->with('change.user')
            ->orderBy('aspect')
            ->get()
            ->groupBy('app_instance_id')
            ->map(fn (Collection $states) => $states->map(fn (UpdateState $state) => [
                'key' => $state->aspect,
                'label' => $this->aspects->label($state->aspect),
                'since' => $state->drift_detected_at?->toIso8601String(),
                'change' => $this->change($state),
            ])->values()->all())
            ->all();
    }

    /**
     * What one instance is out of date for.
     *
     * @return array<int, array{key: string, label: string, since: string|null, change: array<string, mixed>|null}>
     */
    public function forInstance(AppInstance $app_instance): array
    {
        return $this->byInstance([$app_instance->id])[$app_instance->id] ?? [];
    }

    /**
     * How many instances of each organization are out of date.
     *
     * @param  array<int, int>  $organization_ids
     * @return array<int, int> by organization id
     */
    public function byOrganization(array $organization_ids): array
    {
        $instances = AppInstance::whereIn('organization_id', $organization_ids)->pluck('organization_id', 'id');

        if ($instances->isEmpty()) {
            return [];
        }

        return $this->query()
            ->whereIn('app_instance_id', $instances->keys())
            ->distinct()
            ->pluck('app_instance_id')
            ->countBy(fn ($id) => $instances[$id])
            ->all();
    }

    /**
     * The edit an out of date aspect was put out of date by, as it is shown.
     *
     * @return array<string, mixed>|null
     */
    private function change(?UpdateState $state): ?array
    {
        $change = $state?->change;

        return $change ? [
            'id' => $change->id,
            'subject' => $change->subject_label,
            'action' => $change->action,
            'changed' => $change->changed,
            'by' => $change->user?->name,
            'at' => $change->created_at?->toIso8601String(),
        ] : null;
    }
}
