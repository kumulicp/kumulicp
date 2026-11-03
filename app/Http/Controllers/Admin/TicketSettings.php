<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Services\Tickets\TicketAutomation;
use App\Services\Tickets\TicketStaff;
use App\Ticket;
use App\TicketCategory;
use App\TicketSlaPolicy;
use App\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Service level policies, the housekeeping rules and which policy each
 * category follows. Targets are entered in hours and stored in minutes.
 */
class TicketSettings extends Controller
{
    public function __construct(
        private TicketAutomation $automation,
        private TicketStaff $staff,
    ) {}

    public function show()
    {
        Gate::authorize('manage', Ticket::class);

        return inertia('Admin/Tickets/TicketSettings', [
            'policies' => TicketSlaPolicy::orderBy('name')->get()->map(fn (TicketSlaPolicy $policy) => $this->present($policy))->values()->all(),
            'categories' => TicketCategory::where('active', true)->orderBy('name')->get(['id', 'name', 'sla_policy_id'])
                ->map(fn (TicketCategory $category) => ['id' => $category->id, 'name' => $category->name, 'sla_policy_id' => $category->sla_policy_id])->all(),
            'automation' => collect($this->automation->settings())->except('rr_last')->all(),
            'staff' => $this->staff->users()->sortBy('name')->values()->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'priorities' => collect(TicketPriority::cases())->map(fn ($case) => ['value' => $case->value, 'text' => $case->label()])->all(),
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.settings.title')],
            ],
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $policy = new TicketSlaPolicy(['name' => $request->validate(['name' => ['required', 'string', 'max:100']])['name']]);
        $policy->active = true;
        $policy->is_default = TicketSlaPolicy::count() === 0;
        $policy->targets = array_fill_keys(array_column(TicketPriority::cases(), 'value'), ['first_response' => null, 'resolution' => null]);
        $policy->business_hours = null;
        $policy->save();

        return back()->with('success', __('tickets.settings.policy_created'));
    }

    public function updatePolicy(Request $request, TicketSlaPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $data = $request->validate($this->policyRules());

        DB::transaction(function () use ($policy, $data) {
            if ($data['is_default'] ?? false) {
                TicketSlaPolicy::where('id', '!=', $policy->id)->update(['is_default' => false]);
            }

            $policy->name = $data['name'];
            $policy->active = (bool) ($data['active'] ?? true);
            $policy->is_default = (bool) ($data['is_default'] ?? false);
            $policy->targets = $this->targets($data['targets'] ?? []);
            $policy->business_hours = ($data['business_hours']['enabled'] ?? false) ? [
                'timezone' => $data['business_hours']['timezone'],
                'days' => array_values(array_unique(array_map('intval', $data['business_hours']['days']))),
                'start' => $data['business_hours']['start'],
                'end' => $data['business_hours']['end'],
                'holidays' => array_values(array_unique($data['business_hours']['holidays'] ?? [])),
            ] : null;
            $policy->save();
        });

        return back()->with('success', __('tickets.settings.policy_saved'));
    }

    public function destroyPolicy(TicketSlaPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        TicketCategory::where('sla_policy_id', $policy->id)->update(['sla_policy_id' => null]);
        // Open tickets keep their due times until something changes them; none are lost
        $policy->delete();

        return back()->with('success', __('tickets.settings.policy_deleted'));
    }

    public function updateCategory(Request $request, TicketCategory $category): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $data = $request->validate(['sla_policy_id' => ['nullable', 'integer', Rule::exists('ticket_sla_policies', 'id')]]);

        $category->sla_policy_id = $data['sla_policy_id'] ?? null;
        $category->save();

        return back()->with('success', __('tickets.settings.category_saved'));
    }

    public function updateAutomation(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Ticket::class);

        $data = $request->validate([
            'auto_assign' => ['required', Rule::in(TicketAutomation::MODES)],
            'pool_ids' => ['present', 'array'],
            'pool_ids.*' => ['integer'],
            'reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'auto_resolve_days' => ['required', 'integer', 'min:0', 'max:365'],
            'auto_close_days' => ['required', 'integer', 'min:0', 'max:365'],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $this->automation->update($data);

        return back()->with('success', __('tickets.settings.automation_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function policyRules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'targets' => ['sometimes', 'array'],
            'business_hours' => ['sometimes', 'array'],
            'business_hours.enabled' => ['sometimes', 'boolean'],
            'business_hours.timezone' => ['required_if:business_hours.enabled,true', 'nullable', 'timezone:all'],
            'business_hours.days' => ['required_if:business_hours.enabled,true', 'nullable', 'array', 'min:1'],
            'business_hours.days.*' => ['integer', 'between:1,7'],
            'business_hours.start' => ['required_if:business_hours.enabled,true', 'nullable', 'date_format:H:i'],
            'business_hours.end' => ['required_if:business_hours.enabled,true', 'nullable', 'date_format:H:i', 'after:business_hours.start'],
            'business_hours.holidays' => ['sometimes', 'nullable', 'array', 'max:200'],
            'business_hours.holidays.*' => ['date_format:Y-m-d'],
        ];

        foreach (TicketPriority::cases() as $priority) {
            foreach (['first_response', 'resolution'] as $clock) {
                $rules["targets.{$priority->value}.{$clock}"] = ['nullable', 'numeric', 'min:0', 'max:8760'];
            }
        }

        return $rules;
    }

    /**
     * Hours typed by an administrator become minutes (blank or zero = no target).
     *
     * @param  array<string, array<string, mixed>>  $input
     * @return array<string, array{first_response: int|null, resolution: int|null}>
     */
    private function targets(array $input): array
    {
        $targets = [];

        foreach (TicketPriority::cases() as $priority) {
            foreach (['first_response', 'resolution'] as $clock) {
                $hours = (float) ($input[$priority->value][$clock] ?? 0);
                $targets[$priority->value][$clock] = $hours > 0 ? (int) round($hours * 60) : null;
            }
        }

        return $targets;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TicketSlaPolicy $policy): array
    {
        $hours = $policy->business_hours;

        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'active' => $policy->active,
            'is_default' => $policy->is_default,
            // Shown back in hours
            'targets' => collect(TicketPriority::cases())->mapWithKeys(fn ($priority) => [$priority->value => [
                'first_response' => ($m = $policy->target($priority->value, 'first_response')) ? round($m / 60, 2) : null,
                'resolution' => ($m = $policy->target($priority->value, 'resolution')) ? round($m / 60, 2) : null,
            ]])->all(),
            'business_hours' => [
                'enabled' => $hours !== null,
                'timezone' => $hours['timezone'] ?? config('app.timezone', 'UTC'),
                'days' => $hours['days'] ?? [1, 2, 3, 4, 5],
                'start' => $hours['start'] ?? '09:00',
                'end' => $hours['end'] ?? '17:00',
                'holidays' => $hours['holidays'] ?? [],
            ],
        ];
    }
}
