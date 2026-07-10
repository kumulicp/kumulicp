<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Security\RunSecurityScan;
use App\Http\Controllers\Controller;
use App\OrgServer;
use App\SecurityScan;
use App\Support\Facades\Action;
use App\Support\Facades\SecurityTool;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SecurityScans extends Controller
{
    public function index(Request $request)
    {
        $scans = SecurityScan::with('org_server.organization')->orderByDesc('created_at');

        if ($request->org_server_id) {
            $scans->where('org_server_id', $request->org_server_id);
        }
        if ($request->tool) {
            $scans->where('tool', $request->tool);
        }
        if ($request->status) {
            $scans->where('status', $request->status);
        }
        if ($request->date_from) {
            $scans->where('created_at', '>=', Carbon::parse($request->date_from)->startOfDay());
        }
        if ($request->date_to) {
            $scans->where('created_at', '<=', Carbon::parse($request->date_to)->endOfDay());
        }

        $severity = $request->severity;
        if ($severity) {
            $scans->whereHas('findings', function ($query) use ($severity) {
                $query->where('severity', $severity);
            });
        }

        $scans = $scans->paginate(20);

        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach (SecurityScan::query()
            ->when($request->date_from, fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->date_from)->startOfDay()))
            ->when($request->date_to, fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->date_to)->endOfDay()))
            ->get() as $scan) {
            foreach ($scan->summary ?? [] as $key => $count) {
                if (array_key_exists($key, $summary)) {
                    $summary[$key] += $count;
                }
            }
        }

        return inertia()->render('Admin/Security/ScansList', [
            'scans' => $scans->map(fn ($scan) => $this->mapScan($scan)),
            'meta' => [
                'total' => $scans->total(),
                'pages' => $scans->lastPage(),
                'page' => $scans->currentPage(),
            ],
            'summary' => $summary,
            'org_servers' => OrgServer::with('organization', 'server')->get()->map(fn ($org_server) => [
                'id' => $org_server->id,
                'name' => ($org_server->organization->name ?? 'Org').' - '.($org_server->server->name ?? $org_server->id),
            ]),
            'tools' => SecurityTool::all(),
            'breadcrumbs' => [
                ['label' => __('admin.security.scans')],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'org_server_id' => 'required|integer|exists:org_servers,id',
            'tool' => 'required|in:'.implode(',', SecurityTool::all()),
        ]);

        $org_server = OrgServer::find($validated['org_server_id']);

        $security_scan = new SecurityScan;
        $security_scan->org_server_id = $org_server->id;
        $security_scan->tool = $validated['tool'];
        $security_scan->status = 'pending';
        $security_scan->triggered_by = 'manual';
        $security_scan->save();

        $task = Action::execute(new RunSecurityScan($org_server, $validated['tool'], $security_scan));

        if ($task) {
            $security_scan->task_id = $task->id;
            $security_scan->save();
        }

        return redirect('/admin/server/security/scans')->with('success', __('admin.security.scan_started'));
    }

    public function show(SecurityScan $scan)
    {
        $scan->load('findings', 'org_server.organization');

        return inertia()->render('Admin/Security/ScanDetail', [
            'scan' => $this->mapScan($scan, true),
            'breadcrumbs' => [
                ['label' => __('admin.security.scans'), 'url' => '/admin/server/security/scans'],
                ['label' => $scan->tool],
            ],
        ]);
    }

    public function destroy(SecurityScan $scan)
    {
        $scan->delete();

        return redirect('/admin/server/security/scans')->with('success', __('admin.security.scan_deleted'));
    }

    private function mapScan(SecurityScan $scan, bool $with_findings = false): array
    {
        $data = [
            'id' => $scan->id,
            'tool' => $scan->tool,
            'status' => $scan->status,
            'triggered_by' => $scan->triggered_by,
            'summary' => $scan->summary,
            'organization' => $scan->org_server?->organization?->name,
            'org_server_id' => $scan->org_server_id,
            'started_at' => $scan->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $scan->finished_at?->format('Y-m-d H:i:s'),
            'error_message' => $scan->error_message,
            'created_at' => $scan->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($with_findings) {
            $data['findings'] = $scan->findings->map(fn ($finding) => [
                'id' => $finding->id,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'category' => $finding->category,
                'description' => $finding->description,
                'remediation' => $finding->remediation,
                'rule_id' => $finding->rule_id,
            ]);
            $data['raw_output'] = $scan->raw_output;
        }

        return $data;
    }
}
