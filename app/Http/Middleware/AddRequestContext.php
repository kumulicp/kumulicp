<?php

namespace App\Http\Middleware;

use App\Organization;
use App\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tags every log record written during a web request (and any job it queues,
 * Context travels with queued jobs) with a request id, the acting user and
 * organization, so a single request can be followed across log lines.
 */
class AddRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request_id = $request->header('X-Request-Id');

        // Reuse the id from the ingress / load balancer when it looks sane, otherwise make one.
        if (! is_string($request_id) || ! preg_match('/^[A-Za-z0-9._-]{8,64}$/', $request_id)) {
            $request_id = (string) Str::uuid();
        }

        Context::add([
            'request_id' => $request_id,
            'route' => $request->route()?->getName() ?? $request->method().' '.$request->path(),
        ]);

        $this->addActor($request);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $request_id);

        return $response;
    }

    private function addActor(Request $request): void
    {
        try {
            $user = $request->user();
        } catch (Throwable) {
            return;
        }

        if ($user instanceof User) {
            Context::add(['user_id' => $user->id, 'organization_id' => $user->organization?->id]);
        } elseif ($user instanceof Organization) {
            Context::add(['organization_id' => $user->id]);
        }
    }
}
