<?php

namespace App\Http\Controllers\Admin;

use App\AppInstance;
use App\Console\Calls\ScanUpdateDrift;
use App\Http\Controllers\Controller;
use App\Updates\PendingUpdates;
use App\Updates\UpdateDrift;
use App\UpdateState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** What app instances are out of date for, and why (see App\Updates\PendingUpdates) */
class Updates extends Controller
{
    public function __construct(private readonly PendingUpdates $pending) {}

    public function index(Request $request)
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

        if (! empty($validated['aspects'])) {
            $states->whereIn('aspect', $validated['aspects']);
        }

        $by_instance = $states->get(['app_instance_id', 'aspect'])->groupBy('app_instance_id');

        AppInstance::whereIn('id', $by_instance->keys())->get()->each(
            fn (AppInstance $instance) => $drift->dismiss(
                $instance,
                $by_instance[$instance->id]->pluck('aspect')->all(),
                $request->user()->id,
            )
        );

        return back()->with('success', __('admin.updates.dismissed', ['count' => $by_instance->count()]));
    }

    /** Looks at every app instance again, as the nightly scan does */
    public function scan(): RedirectResponse
    {
        (new ScanUpdateDrift)();

        return back()->with('success', __('admin.updates.scanning'));
    }
}
