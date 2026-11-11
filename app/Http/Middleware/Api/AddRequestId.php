<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags every API request with an id: the caller's X-Request-Id when it looks
 * sane, otherwise a fresh UUID. The id is returned in the X-Request-Id header,
 * included in error bodies, and added to the log context so a customer-reported
 * id can be traced through the logs.
 */
class AddRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = static::resolve($request);

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * The request's id, generated once per request. Safe to call from places
     * that run outside this middleware, such as the exception renderer.
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get('request_id');

        if (is_string($existing)) {
            return $existing;
        }

        $incoming = $request->headers->get(self::HEADER);

        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $id);

        return $id;
    }
}
