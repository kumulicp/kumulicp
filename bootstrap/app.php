<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetupAwareAuthenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;

return Application::configure(basePath: dirname(__DIR__))

    ->withEvents(discover: false)

    ->withRouting(

        web: __DIR__.'/../routes/web.php',

        commands: __DIR__.'/../routes/console.php',

        api: __DIR__.'/../routes/api.php',

        health: '/up',

    )

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'auth' => SetupAwareAuthenticate::class,
            'guest' => RedirectIfAuthenticated::class,
            'toggle' => EnsureFeatureEnabled::class,
        ]);
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
        ],
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // SecurityHeaders (the 'web'-group middleware) never gets to run its
            // post-$next() code for exception-driven responses - Laravel's
            // pipeline unwinds straight past it. Reuse the nonce it already
            // generated earlier in this request (or generate one, if the
            // exception happened before that middleware ran) and reapply the
            // same headers here so error pages aren't left unprotected.
            $nonce = Vite::cspNonce() ?? Vite::useCspNonce();

            // In debug mode this $response is already Ignition's rendered
            // page. It relies on inline <script> tags it has no way to tag
            // with our CSP nonce, so applying the app's strict script-src
            // (nonce-only, no 'unsafe-inline') to it blocks every script and
            // leaves a blank page - surfaced as an empty modal, since
            // Inertia shows non-Inertia responses like this one in a modal.
            // Debug pages are dev-only by definition, so skip the app's CSP
            // for them entirely rather than try to patch Ignition's markup.
            if (config('app.debug') && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                return $response;
            }

            if (! app()->environment(['testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                if (Auth::check() && auth()?->user()?->hasVerifiedEmail()) {
                    try {
                        return SecurityHeaders::apply(
                            inertia('ErrorPage', [
                                'status' => $response->getStatusCode(),
                                'message' => $exception->getMessage(),
                            ])
                                ->toResponse($request)
                                ->setStatusCode($response->getStatusCode()),
                            $request,
                            $nonce,
                        );
                    } catch (Throwable $e) {
                    }
                } else {
                    try {
                        return SecurityHeaders::apply(
                            inertia('UnauthenticatedErrorPage', [
                                'status' => $response->getStatusCode(),
                                'message' => $exception->getMessage(),
                            ])
                                ->toResponse($request)
                                ->setStatusCode($response->getStatusCode()),
                            $request,
                            $nonce,
                        );
                    } catch (Throwable $e) {
                    }
                }
            } elseif ($response->getStatusCode() === 419) {
                return SecurityHeaders::apply(
                    back()->with([
                        'message' => 'The page expired, please try again.',
                    ]),
                    $request,
                    $nonce,
                );
            }

            return SecurityHeaders::apply($response, $request, $nonce);
        });
        $exceptions->dontReportDuplicates();
        $exceptions->dontReport([
            TransportException::class,
        ]);
    })->create();
