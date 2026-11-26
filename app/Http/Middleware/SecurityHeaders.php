<?php

namespace App\Http\Middleware;

use App\Support\Facades\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Throwable;
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
        $captcha = self::captchaSources($request);
        // Adds the captcha provider's hosts to a directive, on the pages that show its widget
        $with = static fn (string $directive): string => $captcha === [] ? $directive : $directive.' '.implode(' ', $captcha);

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
                // Ignition's error page - shown inline via an iframe with
                // srcdoc, which per spec inherits the CSP of *this* page,
                // not any header on the failed request/response - injects
                // its own inline <script> tags with no way to carry our
                // nonce. A nonce present in this directive makes browsers
                // ignore 'unsafe-inline' entirely (that's intentional, to
                // stop exactly this kind of "just add it as a fallback"
                // pattern), so the nonce has to be dropped in debug mode
                // for 'unsafe-inline' to actually take effect. Debug mode
                // is dev-only, so this never reaches production.
                $with(config('app.debug')
                    ? "script-src 'self' 'unsafe-inline' https://js.stripe.com"
                    : "script-src 'self' 'nonce-{$nonce}' https://js.stripe.com"),
                $with("style-src 'self' 'unsafe-inline'"),
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                $with("connect-src 'self' https://api.stripe.com"),
                'worker-src blob:',
                "object-src 'none'",
                $with("frame-src 'self' https://js.stripe.com https://hooks.stripe.com"),
                "base-uri 'self'",
                "form-action 'self'",
            ]);

        // A response may already carry a stricter policy of its own (ticket
        // attachment downloads do); never replace that with the page one.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $csp);
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * The hosts the captcha widget needs (its script, its frame, what it connects to), for the pages
     * that show it: the public registration pages, and only while a provider is set. Everywhere else
     * the policy stays as strict as before.
     *
     * @return array<int, string>
     */
    private static function captchaSources(Request $request): array
    {
        if (! $request->is('public/org/*/register*')) {
            return [];
        }

        // This also runs for error pages, where the settings table may be the very thing that failed
        try {
            $provider = Settings::get('captcha_provider');
        } catch (Throwable) {
            return [];
        }

        return match ($provider) {
            'turnstile' => ['https://challenges.cloudflare.com'],
            'hcaptcha' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
            default => [],
        };
    }
}
