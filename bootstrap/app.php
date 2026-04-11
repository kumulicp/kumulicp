<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\UserOrganization;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(

        web: __DIR__.'/../routes/web.php',

        commands: __DIR__.'/../routes/console.php',

        api: __DIR__.'/../routes/api.php',

        health: '/up',

    )

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'user.organization' => UserOrganization::class,
            'guest' => RedirectIfAuthenticated::class,
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
            if (! app()->environment(['testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                if (Auth::check() && auth()?->user()?->hasVerifiedEmail()) {
                    try {
                        return inertia('ErrorPage', [
                            'status' => $response->getStatusCode(),
                            'message' => $exception->getMessage(),
                        ])
                            ->toResponse($request)
                            ->setStatusCode($response->getStatusCode());
                    } catch (Throwable $e) {
                    }
                } else {
                    try {
                        return inertia('UnauthenticatedErrorPage', [
                            'status' => $response->getStatusCode(),
                            'message' => $exception->getMessage(),
                        ])
                            ->toResponse($request)
                            ->setStatusCode($response->getStatusCode());
                    } catch (Throwable $e) {
                    }
                }
            } elseif ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'The page expired, please try again.',
                ]);
            }

            return $response;
        });
        $exceptions->dontReportDuplicates();
        $exceptions->dontReport([
            TransportException::class,
        ]);
    })->create();
