<?php

namespace App\Services\Tickets;

use App\AppInstance;
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
 *   app_rules          per application: who takes turns on tickets about it (application id => pool_ids);
 *                      an application with no rule uses pool_ids above
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
        'app_rules' => [],
        'reminder_days' => 3,
        'auto_resolve_days' => 7,
        'auto_close_days' => 7,
        'retention_days' => 0,
        // Bookkeeping for round robin: who got the last ticket
        'rr_last' => null,
        'rr_by_app' => [],
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

        if (array_key_exists('app_rules', $input)) {
            $settings['app_rules'] = $this->cleanRules((array) $input['app_rules']);
            // Turn-taking state of an application that no longer has a rule is not kept
            $settings['rr_by_app'] = array_intersect_key((array) $settings['rr_by_app'], $settings['app_rules']);
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
     *
     * A ticket about an app whose application has its own rule is shared out
     * among the people chosen for that application; anything else uses the
     * general pool. The same method (round robin or least loaded) applies to both.
     */
    public function pickAssignee(?Ticket $ticket = null): ?User
    {
        $settings = $this->settings();

        if ($settings['auto_assign'] === 'off') {
            return null;
        }

        $applicationId = $this->applicationOf($ticket);
        $pool = $applicationId ? $this->pool($settings['app_rules'][$applicationId] ?? []) : collect();

        // No rule for this application, or nobody on it is available: the general pool
        $byApp = $pool->isNotEmpty();
        if (! $byApp) {
            $pool = $this->pool($settings['pool_ids']);
        }

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

        // Round robin: the next person after the one who got the last ticket (each pool has its own turn)
        $last = (int) ($byApp ? ($settings['rr_by_app'][$applicationId] ?? 0) : ($settings['rr_last'] ?? 0));
        $next = $pool->first(fn (User $user) => $user->id > $last) ?? $pool->first();

        if ($byApp) {
            $settings['rr_by_app'][$applicationId] = $next->id;
        } else {
            $settings['rr_last'] = $next->id;
        }

        $this->store($settings);

        return $next;
    }

    /**
     * The application (not the customer's instance of it) a ticket is about, if any.
     */
    private function applicationOf(?Ticket $ticket): ?int
    {
        if (! $ticket?->app_instance_id) {
            return null;
        }

        return AppInstance::whereKey($ticket->app_instance_id)->value('application_id');
    }

    /**
     * Rules arrive as a list of {application_id, pool_ids}; they are kept as a map
     * from application id to its people. A rule without people, or repeated, is dropped.
     *
     * @param  array<int|string, mixed>  $rules
     * @return array<int, list<int>>
     */
    private function cleanRules(array $rules): array
    {
        $clean = [];

        foreach ($rules as $key => $rule) {
            // Accept both the list the form sends and the stored map
            $isListEntry = is_array($rule) && array_key_exists('application_id', $rule);
            $applicationId = $isListEntry ? (int) $rule['application_id'] : (int) $key;
            $people = $isListEntry ? ($rule['pool_ids'] ?? []) : (array) $rule;
            $people = array_values(array_unique(array_filter(array_map('intval', (array) $people), fn (int $id) => $id > 0)));

            if ($applicationId > 0 && $people !== [] && ! isset($clean[$applicationId])) {
                $clean[$applicationId] = $people;
            }
        }

        return $clean;
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
