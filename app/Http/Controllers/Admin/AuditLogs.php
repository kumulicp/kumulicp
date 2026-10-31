<?php

namespace App\Http\Controllers\Admin;

use App\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\Facades\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogs extends Controller
{
    private const EXPORT_LIMIT = 10000;

    public function index(Request $request)
    {
        $entries = $this->filtered($request)->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        // Reading the audit trail is itself an auditable act.
        Audit::record('audit.viewed', properties: ['filters' => $this->filters($request)]);

        return inertia()->render('Admin/AuditLogsList', [
            'entries' => $entries->map(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'time' => $entry->occurred_at->format('Y-m-d H:i:s'),
                'event' => $entry->event,
                'outcome' => $entry->outcome,
                'actor' => $entry->actor_label ?? $entry->actor_type,
                'target_organization_id' => $entry->target_organization_id,
                'subject' => $entry->subject_label ?? $entry->subject_id,
                'subject_type' => $entry->subject_type ? class_basename($entry->subject_type) : null,
                'ip' => $entry->ip,
                'route' => $entry->route,
                'source' => $entry->source,
                'diff' => $entry->diff,
                'properties' => $entry->properties,
            ]),
            'meta' => [
                'total' => $entries->total(),
                'pages' => $entries->lastPage(),
                'page' => $entries->currentPage(),
            ],
            'filters' => $this->filters($request),
            'enabled' => Audit::enabled(),
            'retention_days' => config('audit.retention_days'),
            'streaming' => Audit::enabled() && config('audit.channel') !== 'null',
            'breadcrumbs' => [
                ['label' => __('labels.audit_log')],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::EXPORT_LIMIT);

        Audit::record('audit.exported', properties: ['filters' => $this->filters($request)]);

        $columns = ['occurred_at', 'event', 'outcome', 'actor_type', 'actor_id', 'actor_label', 'actor_organization_id', 'target_organization_id', 'subject_type', 'subject_id', 'subject_label', 'ip', 'route', 'source', 'request_id', 'task_id', 'diff', 'properties'];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);

            foreach ($query->get() as $entry) {
                fputcsv($out, array_map(fn ($column) => $this->csvCell($entry->getAttribute($column)), $columns));
            }

            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filtered(Request $request): Builder
    {
        $filters = $this->filters($request);

        return AuditLog::query()
            ->when($filters['event'], fn (Builder $query, string $event) => $query->where('event', 'like', '%'.addcslashes($event, '%_\\').'%'))
            ->when($filters['actor'], fn (Builder $query, string $actor) => $query->where('actor_label', 'like', '%'.addcslashes($actor, '%_\\').'%'))
            ->when($filters['outcome'], fn (Builder $query, string $outcome) => $query->where('outcome', $outcome))
            ->when($filters['organization'], fn (Builder $query, string $organization) => $query->where(fn (Builder $query) => $query
                ->where('target_organization_id', $organization)->orWhere('actor_organization_id', $organization)))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('occurred_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('occurred_at', '<=', $to.' 23:59:59'));
    }

    /**
     * @return array{event: string|null, actor: string|null, outcome: string|null, organization: string|null, from: string|null, to: string|null}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'event' => 'nullable|string|max:100',
            'actor' => 'nullable|string|max:255',
            'outcome' => 'nullable|in:success,failure,denied',
            'organization' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);

        return [
            'event' => $validated['event'] ?? null,
            'actor' => $validated['actor'] ?? null,
            'outcome' => $validated['outcome'] ?? null,
            'organization' => isset($validated['organization']) ? (string) $validated['organization'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * Spreadsheet applications run cells that start with = + - @ as formulas, so those are neutralised.
     */
    private function csvCell(mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value);
        } elseif ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s.u');
        }

        $cell = (string) $value;

        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
