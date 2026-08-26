<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generated before $next() so @vite-rendered tags carry it, and so
        // Laravel Debugbar's own CSP-nonce lookup (Vite::cspNonce()) sees the
        // same value for the inline <script> it injects into the response.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        return static::apply($response, $request, $nonce);
    }

    // Exceptions thrown inside the 'web' middleware group (404s, auth
    // redirects, 419s, ...) unwind straight past this middleware's code
    // below $next() - Laravel's pipeline never runs it for those responses.
    // bootstrap/app.php's exception handler calls this directly so the
    // rendered error pages get the same headers as everything else.
    public static function apply(Response $response, Request $request, string $nonce): Response
    {
        // laravel-filemanager's own views (loaded in an iframe at
        // /laravel-filemanager) lean heavily on inline onclick="" handlers and
        // ajax-swapped <script> blocks. Nonces can't authorize either of those
        // (nonces only cover <script> elements present when the page carrying
        // that nonce was parsed, and CSP has no nonce mechanism for inline
        // event-handler attributes at all), so patching that vendor package's
        // views script-by-script doesn't actually work. Scope a relaxed,
        // nonce-free policy to just that route instead of weakening the CSP
        // for the whole app.
        $csp = $request->is('laravel-filemanager*')
            ? implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ])
            : implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}' https://js.stripe.com",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self' https://api.countrystatecity.in https://api.stripe.com",
                "worker-src blob:",
                "object-src 'none'",
                "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
                "base-uri 'self'",
                "form-action 'self'",
            ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
