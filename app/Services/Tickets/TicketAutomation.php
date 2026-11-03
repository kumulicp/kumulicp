<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\ServerSetting;
use App\Ticket;
use App\User;
use Illuminate\Support\Collection;

/**
 * The housekeeping rules an administrator can set, stored as one JSON setting:
 *
 *   auto_assign        off | round_robin | least_loaded  (who gets new unassigned tickets)
 *   pool_ids           staff taking part in auto-assign (empty = all staff)
 *   reminder_days      remind the customer after this many days waiting on them (0 = never)
 *   auto_resolve_days  resolve a ticket after this many days of silence from the customer (0 = never)
 *   auto_close_days    close a resolved ticket after this many days (0 = never)
 *   retention_days     delete closed tickets after this many days (0 = keep for ever)
 */
class TicketAutomation
{
    public const KEY = 'tickets_automation';

    public const MODES = ['off', 'round_robin', 'least_loaded'];

    private const DEFAULTS = [
        'auto_assign' => 'off',
        'pool_ids' => [],
        'reminder_days' => 3,
        'auto_resolve_days' => 7,
        'auto_close_days' => 7,
        'retention_days' => 0,
        // Bookkeeping for round robin: who got the last ticket
        'rr_last' => null,
    ];

    public function __construct(private TicketStaff $staff) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $stored = json_decode((string) ServerSetting::where('key', self::KEY)->value('value'), true);

        return array_merge(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
    }

    /**
     * Save new rules. Anything not given keeps its value; unknown keys are ignored.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(array $input): void
    {
        $settings = $this->settings();

        if (isset($input['auto_assign']) && in_array($input['auto_assign'], self::MODES, true)) {
            $settings['auto_assign'] = $input['auto_assign'];
        }

        if (isset($input['pool_ids'])) {
            $settings['pool_ids'] = array_values(array_unique(array_map('intval', (array) $input['pool_ids'])));
        }

        foreach (['reminder_days', 'auto_resolve_days', 'auto_close_days', 'retention_days'] as $key) {
            if (isset($input[$key])) {
                $settings[$key] = max(0, min(3650, (int) $input[$key]));
            }
        }

        $this->store($settings);
    }

    /**
     * Who should get a new ticket nobody has been assigned to, or null when
     * automatic assignment is off or nobody is available.
     */
    public function pickAssignee(): ?User
    {
        $settings = $this->settings();

        if ($settings['auto_assign'] === 'off') {
            return null;
        }

        $pool = $this->pool($settings['pool_ids']);

        if ($pool->isEmpty()) {
            return null;
        }

        if ($settings['auto_assign'] === 'least_loaded') {
            $open = Ticket::whereIn('status', TicketStatus::openValues())
                ->whereIn('support_assignee_id', $pool->pluck('id'))
                ->selectRaw('support_assignee_id, count(*) as total')
                ->groupBy('support_assignee_id')
                ->pluck('total', 'support_assignee_id');

            // Fewest open tickets; the lower id wins a tie so the choice is stable
            return $pool->sortBy(fn (User $user) => [(int) ($open[$user->id] ?? 0), $user->id])->first();
        }

        // Round robin: the next person after the one who got the last ticket
        $last = (int) ($settings['rr_last'] ?? 0);
        $next = $pool->first(fn (User $user) => $user->id > $last) ?? $pool->first();

        $settings['rr_last'] = $next->id;
        $this->store($settings);

        return $next;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function pool(array $ids): Collection
    {
        return $this->staff->users()
            ->filter(fn (User $user) => $this->staff->isActive($user))
            ->when($ids !== [], fn (Collection $users) => $users->whereIn('id', $ids))
            ->sortBy('id')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function store(array $settings): void
    {
        $row = ServerSetting::where('key', self::KEY)->first() ?? new ServerSetting;
        $row->key = self::KEY;
        $row->value = json_encode($settings);
        $row->save();
    }
}
