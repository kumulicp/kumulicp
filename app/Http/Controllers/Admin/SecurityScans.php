<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Security\RunSecurityScan;
use App\AppInstance;
use App\Http\Controllers\Controller;
use App\OrgDomain;
use App\OrgServer;
use App\SecurityFinding;
use App\SecurityScan;
use App\SecurityScanSavedValue;
use App\Support\Facades\Action;
use App\Support\Facades\SecurityTool;
use App\Support\Security\Tools\TrivyTool;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SecurityScans extends Controller
{
    public function index(Request $request)
    {
        $scans = SecurityScan::with('org_server.organization')
            ->withCount([
                'findings',
                'findings as resolved_findings_count' => fn ($query) => $query->resolved(),
            ])
            ->orderByDesc('created_at');

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
                'organization_id' => $org_server->organization_id,
                'name' => ($org_server->organization->name ?? 'Org').' - '.($org_server->server->name ?? $org_server->id),
            ]),
            'tools' => SecurityTool::all(),
            'tool_descriptions' => collect(SecurityTool::all())->mapWithKeys(fn ($tool) => [
                $tool => SecurityTool::profile($tool)->description(),
            ]),
            'tools_requiring_targets' => collect(SecurityTool::all())
                ->filter(fn ($tool) => SecurityTool::profile($tool)->requiresTargets())
                ->values(),
            'tools_supporting_severity_filter' => collect(SecurityTool::all())
                ->filter(fn ($tool) => SecurityTool::profile($tool)->supportsSeverityFilter())
                ->values(),
            'tools_supporting_namespace_filter' => collect(SecurityTool::all())
                ->filter(fn ($tool) => SecurityTool::profile($tool)->supportsNamespaceFilter())
                ->values(),
            'severities' => TrivyTool::SEVERITIES,
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
            'app_domain_targets' => 'array',
            'app_domain_targets.*' => 'string|max:255',
            'custom_domains' => 'array',
            'custom_domains.*' => 'string|max:255',
            'severity' => 'array',
            'severity.*' => 'string|in:'.implode(',', TrivyTool::SEVERITIES),
            'ignore_unfixed' => 'boolean',
            'include_org_namespace' => 'boolean',
            'custom_namespaces' => 'array',
            'custom_namespaces.*' => 'string|max:255',
        ]);

        $org_server = OrgServer::find($validated['org_server_id']);
        $profile = SecurityTool::profile($validated['tool']);
        $targets = array_values(array_unique(array_merge($validated['app_domain_targets'] ?? [], $validated['custom_domains'] ?? [])));

        $namespaces = $validated['custom_namespaces'] ?? [];
        if ($validated['include_org_namespace'] ?? true) {
            $namespaces[] = $org_server->organization->slug;
        }

        $options = [
            'severity' => $validated['severity'] ?? [],
            'ignore_unfixed' => $validated['ignore_unfixed'] ?? false,
            'namespaces' => array_values(array_unique($namespaces)),
        ];

        if ($profile->requiresTargets() && empty($targets)) {
            return back()->withErrors(['targets' => __('admin.security.targets_required')]);
        }

        if ($profile->supportsSeverityFilter() && empty($options['severity'])) {
            return back()->withErrors(['severity' => __('admin.security.severity_required')]);
        }

        foreach ($validated['custom_domains'] ?? [] as $domain) {
            SecurityScanSavedValue::firstOrCreate([
                'organization_id' => $org_server->organization_id,
                'type' => SecurityScanSavedValue::TYPE_DOMAIN,
                'value' => $domain,
            ]);
        }

        foreach ($validated['custom_namespaces'] ?? [] as $namespace) {
            SecurityScanSavedValue::firstOrCreate([
                'organization_id' => $org_server->organization_id,
                'type' => SecurityScanSavedValue::TYPE_NAMESPACE,
                'value' => $namespace,
            ]);
        }

        $security_scan = new SecurityScan;
        $security_scan->org_server_id = $org_server->id;
        $security_scan->tool = $validated['tool'];
        $security_scan->status = 'pending';
        $security_scan->triggered_by = 'manual';
        $security_scan->save();

        $task = Action::execute(new RunSecurityScan($org_server, $validated['tool'], $security_scan, $targets, $options));

        if ($task) {
            $security_scan->task_id = $task->id;
            $security_scan->save();
        }

        return redirect('/admin/server/security/scans')->with('success', __('admin.security.scan_started'));
    }

    public function apps(Request $request)
    {
        $validated = $request->validate([
            'org_server_id' => 'required|integer|exists:org_servers,id',
        ]);

        $org_server = OrgServer::find($validated['org_server_id']);

        $apps = AppInstance::where('organization_id', $org_server->organization_id)->get();

        return response()->json($apps->map(fn ($app) => [
            'value' => $app->id,
            'text' => $app->label ?: $app->name,
        ]));
    }

    public function appDomains(AppInstance $app_instance)
    {
        $domains = OrgDomain::where('app_instance_id', $app_instance->id)->where('type', 'app')->get();

        return response()->json($domains->map(fn ($domain) => [
            'value' => $domain->name,
            'text' => $domain->name,
        ]));
    }

    public function customDomains(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $domains = SecurityScanSavedValue::type(SecurityScanSavedValue::TYPE_DOMAIN)
            ->where('organization_id', $validated['organization_id'])
            ->orderBy('value')
            ->get();

        return response()->json($domains->map(fn ($domain) => [
            'value' => $domain->value,
            'text' => $domain->value,
        ]));
    }

    public function customNamespaces(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $namespaces = SecurityScanSavedValue::type(SecurityScanSavedValue::TYPE_NAMESPACE)
            ->where('organization_id', $validated['organization_id'])
            ->orderBy('value')
            ->get();

        return response()->json($namespaces->map(fn ($namespace) => [
            'value' => $namespace->value,
            'text' => $namespace->value,
        ]));
    }

    public function storeCustomDomain(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'domain' => 'required|string|max:255',
        ]);

        $domain = SecurityScanSavedValue::firstOrCreate([
            'organization_id' => $validated['organization_id'],
            'type' => SecurityScanSavedValue::TYPE_DOMAIN,
            'value' => $validated['domain'],
        ]);

        return response()->json(['value' => $domain->value, 'text' => $domain->value]);
    }

    public function storeCustomNamespace(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
            'namespace' => 'required|string|max:255',
        ]);

        $namespace = SecurityScanSavedValue::firstOrCreate([
            'organization_id' => $validated['organization_id'],
            'type' => SecurityScanSavedValue::TYPE_NAMESPACE,
            'value' => $validated['namespace'],
        ]);

        return response()->json(['value' => $namespace->value, 'text' => $namespace->value]);
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

    public function updateFinding(SecurityScan $scan, SecurityFinding $finding, Request $request)
    {
        abort_unless($finding->security_scan_id === $scan->id, 404);

        $validated = $request->validate([
            'resolved' => 'required|boolean',
        ]);

        $finding->resolved_at = $validated['resolved'] ? now() : null;
        $finding->save();

        return response()->json([
            'id' => $finding->id,
            'resolved' => $finding->isResolved(),
            'resolved_at' => $finding->resolved_at?->format('Y-m-d H:i:s'),
        ]);
    }

    private function mapScan(SecurityScan $scan, bool $with_findings = false): array
    {
        if ($scan->relationLoaded('findings')) {
            $findings_count = $scan->findings->count();
            $resolved_findings_count = $scan->findings->filter(fn ($finding) => $finding->isResolved())->count();
        } else {
            $findings_count = $scan->findings_count ?? 0;
            $resolved_findings_count = $scan->resolved_findings_count ?? 0;
        }

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
            'findings_count' => $findings_count,
            'resolved_findings_count' => $resolved_findings_count,
        ];

        if ($with_findings) {
            $data['findings'] = $scan->findings->map(fn ($finding) => [
                'id' => $finding->id,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'category' => $finding->category,
                'resource_type' => $finding->resource_type,
                'resource_name' => $finding->resource_name,
                'description' => $finding->description,
                'remediation' => $finding->remediation,
                'rule_id' => $finding->rule_id,
                'metadata' => $finding->metadata,
                'resolved' => $finding->isResolved(),
                'resolved_at' => $finding->resolved_at?->format('Y-m-d H:i:s'),
            ]);
            $data['raw_output'] = $scan->raw_output;
        }

        return $data;
    }
}
