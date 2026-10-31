<?php

namespace App\Http\Middleware;

use App\Organization;
use App\Support\Facades\Audit;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records requests that change something, or were refused, in the audit trail.
 *
 * It runs after the response has been sent and stores who made the request, which route
 * and which records it pointed at, and the outcome. Request bodies and query strings are
 * never stored. Many state changes in the control panel are GET routes (remove,
 * deactivate, restart, ...), so those are matched by path (config audit.state_changing_get_pattern)
 * instead of by HTTP verb.
 */
class AuditRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $route = $request->route();

        if (! $route || ! $this->shouldRecord($request, $response)) {
            return;
        }

        $status = $response->getStatusCode();

        Audit::record(
            'http.request',
            properties: [
                'method' => $request->method(),
                'route' => $route->getName(),
                'uri' => $route->uri(),
                'status' => $status,
                'parameters' => $this->parameters($request),
            ],
            outcome: $this->outcome($request, $status),
            target_organization_id: $this->targetOrganizationId($request),
        );
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        $changes_state = ! $request->isMethodSafe()
            || preg_match((string) config('audit.state_changing_get_pattern'), '/'.ltrim($request->path(), '/'));

        return ($changes_state || $response->getStatusCode() === 403) && Audit::enabled();
    }

    private function outcome(Request $request, int $status): string
    {
        if ($status === 403) {
            return 'denied';
        }

        // A rejected form redirects back with validation errors instead of an error status.
        $has_errors = $request->hasSession() && $request->session()->get('errors')?->any();

        return $status >= 400 || $has_errors ? 'failure' : 'success';
    }

    /**
     * Route parameters as "ModelName:key" for bound models, or the raw value.
     *
     * @return array<string, mixed>
     */
    private function parameters(Request $request): array
    {
        return collect($request->route()->parameters())
            ->map(fn ($value) => $value instanceof Model ? class_basename($value).':'.$value->getKey() : (is_scalar($value) ? $value : null))
            ->all();
    }

    private function targetOrganizationId(Request $request): ?int
    {
        foreach ($request->route()->parameters() as $value) {
            if ($value instanceof Organization) {
                return (int) $value->getKey();
            }

            if ($value instanceof Model && is_numeric($value->getAttribute('organization_id'))) {
                return (int) $value->getAttribute('organization_id');
            }
        }

        return null;
    }
}
