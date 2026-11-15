<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Apps\MassReconcile;
use App\AppInstance;
use App\Console\Calls\ScanUpdateDrift;
use App\Http\Controllers\Controller;
use App\Support\Facades\Action;
use App\Updates\PendingUpdates;
use App\Updates\Reconcile;
use App\Updates\UpdateDrift;
use App\Updates\UpdatePolicy;
use App\UpdateState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What app instances are out of date for, and why (see App\Updates\PendingUpdates), and bringing them up to date */
class Updates extends Controller
{
    public function __construct(private readonly PendingUpdates $pending) {}

    public function index(Request $request, UpdatePolicy $policy)
    {
        $filters = $request->validate([
            'change' => ['nullable', 'regex:/^(none|\d+)$/'],
            'aspect' => 'nullable|string|max:100',
        ]);

        $instances = $this->pending->instances($filters);

        return inertia()->render('Admin/Updates/UpdatesList', [
            'summary' => $this->pending->summary(),
            'groups' => $this->pending->groups(),
            'filters' => [
                'change' => $filters['change'] ?? null,
                'aspect' => $filters['aspect'] ?? null,
            ],
            'instances' => $instances->items(),
            'pagination' => [
                'current_page' => $instances->currentPage(),
                'last_page' => $instances->lastPage(),
                'total' => $instances->total(),
                'per_page' => $instances->perPage(),
            ],
            'policy' => [
                'enabled' => $policy->enabled(),
                'aspects' => $policy->all(),
            ],
            'breadcrumbs' => [
                [
                    'label' => __('admin.updates.title'),
                ],
            ],
        ]);
    }

    /**
     * Takes what is out of date as up to date, without running anything: the change
     * needs nothing done to those instances. Either the ones picked or everything
     * a group (an edit, or 'none') has, never more than what was asked for.
     */
    public function dismiss(Request $request, UpdateDrift $drift): RedirectResponse
    {
        $by_instance = $this->selected($request)->get(['app_instance_id', 'aspect'])->groupBy('app_instance_id');

        AppInstance::whereIn('id', $by_instance->keys())->get()->each(
            fn (AppInstance $instance) => $drift->dismiss(
                $instance,
                $by_instance[$instance->id]->pluck('aspect')->all(),
                $request->user()->id,
            )
        );

        return back()->with('success', __('admin.updates.dismissed', ['count' => $by_instance->count()]));
    }

    /** What updating the instances picked would do, before an admin says to */
    public function preview(Request $request, Reconcile $reconcile): JsonResponse
    {
        ['instance_ids' => $instance_ids, 'aspects' => $aspects] = $this->selection($request);
        $options = $this->rolloutOptions($request);

        return response()->json($reconcile->preview($instance_ids, $aspects, $options['canary'], $options['batch']));
    }

    /**
     * Brings the instances picked up to date for what is out of date for them: a few
     * first, then the rest in batches, each once the one before has gone well (see
     * MassReconcile).
     */
    public function apply(Request $request, Reconcile $reconcile): RedirectResponse
    {
        ['instance_ids' => $instance_ids, 'aspects' => $aspects] = $this->selection($request);
        $options = $this->rolloutOptions($request);

        $preview = $reconcile->preview($instance_ids, $aspects, $options['canary'], $options['batch']);

        if ($preview['instances'] === 0) {
            return back()->with('error', __('admin.updates.nothing_to_update'));
        }

        Action::execute(new MassReconcile($instance_ids, $aspects, $options['canary'], $options['batch'], $options['start_time'], $options['end_time']));

        return back()->with('success', __('admin.updates.updating', ['count' => $preview['instances']]));
    }

    /** Whether anything is updated on its own, and which aspects are */
    public function policy(Request $request, UpdatePolicy $policy): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'aspect' => 'required_with:mode|string|max:100',
            'mode' => ['required_with:aspect', Rule::in([UpdatePolicy::AUTO, UpdatePolicy::MANUAL])],
        ]);

        if (isset($validated['enabled'])) {
            $policy->enable((bool) $validated['enabled']);
        }

        if (isset($validated['aspect'])) {
            $policy->set($validated['aspect'], $validated['mode']);
        }

        return back()->with('success', __('admin.updates.policy_saved'));
    }

    /** Looks at every app instance again, as the nightly scan does */
    public function scan(): RedirectResponse
    {
        (new ScanUpdateDrift)();

        return back()->with('success', __('admin.updates.scanning'));
    }

    /**
     * What an admin picked: the instances, or a group of them (an edit, or 'none'),
     * and optionally just some aspects. Never more than that.
     *
     * @return array{instance_ids: array<int, int>, aspects: array<int, string>}
     */
    private function selection(Request $request): array
    {
        $states = $this->selected($request)->get(['app_instance_id', 'aspect']);

        return [
            'instance_ids' => $states->pluck('app_instance_id')->unique()->map(fn ($id) => (int) $id)->values()->all(),
            'aspects' => $states->pluck('aspect')->unique()->values()->all(),
        ];
    }

    /**
     * The out of date aspects that were picked.
     *
     * @return Builder<UpdateState>
     */
    private function selected(Request $request): Builder
    {
        $validated = $request->validate([
            'instance_ids' => 'required_without:change|array|min:1',
            'instance_ids.*' => 'integer|exists:app_instances,id',
            'change' => ['required_without:instance_ids', 'regex:/^(none|\d+)$/'],
            'aspects' => 'nullable|array',
            'aspects.*' => 'string|max:100',
        ]);

        $states = isset($validated['instance_ids'])
            ? $this->pending->query()->whereIn('app_instance_id', $validated['instance_ids'])
            : $this->pending->filtered(['change' => $validated['change']]);

        return $states->when(! empty($validated['aspects']), fn ($query) => $query->whereIn('aspect', $validated['aspects']));
    }

    /**
     * How a rollout goes: how many are updated first, how many after that at a time,
     * and when (a window of the day, which may cross midnight).
     *
     * @return array{canary: int, batch: int, start_time: string|null, end_time: string|null}
     */
    private function rolloutOptions(Request $request): array
    {
        $validated = $request->validate([
            'canary' => 'nullable|integer|min:0|max:20',
            'batch' => 'nullable|integer|min:1|max:100',
            'start_time' => 'nullable|date_format:H:i|required_with:end_time',
            'end_time' => 'nullable|date_format:H:i|required_with:start_time',
        ]);

        return [
            'canary' => $validated['canary'] ?? 1,
            'batch' => $validated['batch'] ?? 10,
            'start_time' => $validated['start_time'] ?? null,
            'end_time' => $validated['end_time'] ?? null,
        ];
    }
}
