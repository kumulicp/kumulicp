<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Tickets\TicketExporter;
use App\Services\Tickets\TicketReports as Reports;
use App\Ticket;
use App\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Numbers for the support desk, and the files that leave it.
 */
class TicketReports extends Controller
{
    public function __construct(
        private Reports $reports,
        private TicketExporter $exporter,
    ) {}

    public function index(Request $request)
    {
        Gate::authorize('manage', Ticket::class);

        [$from, $to] = $this->period($request);

        return inertia('Admin/Tickets/TicketReports', [
            'report' => $this->reports->summary($from, $to),
            'range' => $request->integer('days') ?: null,
            'breadcrumbs' => [
                ['label' => __('tickets.admin.title'), 'url' => '/admin/support/tickets'],
                ['label' => __('tickets.reports.title')],
            ],
        ]);
    }

    /**
     * Tickets opened in the period, one per row.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('manage', Ticket::class);

        [$from, $to] = $this->period($request);

        return response()->streamDownload(function () use ($from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so spreadsheets read accents correctly
            fputcsv($out, $this->exporter->csvHeader());

            foreach ($this->exporter->csvRows($from, $to) as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 'tickets-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Everything one person has put into the support desk (a subject access request).
     */
    public function exportPerson(User $user): JsonResponse
    {
        Gate::authorize('manage', Ticket::class);

        return response()->json($this->exporter->userData($user), 200, [
            'Content-Disposition' => 'attachment; filename="support-data-user-'.$user->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * From `from`/`to` dates, or the last `days` days (default 30). Never longer than a year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.Reports::MAX_DAYS],
        ]);

        $to = $request->filled('to') ? CarbonImmutable::parse($request->query('to'))->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = match (true) {
            $request->filled('from') => CarbonImmutable::parse($request->query('from'))->startOfDay(),
            default => $to->subDays(($request->integer('days') ?: 30) - 1)->startOfDay(),
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > Reports::MAX_DAYS) {
            $from = $to->subDays(Reports::MAX_DAYS)->startOfDay();
        }

        return [$from, $to];
    }
}
