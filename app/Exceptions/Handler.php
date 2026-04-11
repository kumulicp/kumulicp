<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        TransportException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  Request  $request
     * @return Response
     *
     * @throws Throwable
     */
    public function render($request, Throwable $exception)
    {
        $response = parent::render($request, $exception);

        if (! app()->environment(['local', 'testing']) && in_array($response->status(), [500, 503, 404, 403])) {
            if (Auth::check()) {
                return inertia('ErrorPage', ['status' => $response->status()])
                    ->toResponse($request)
                    ->setStatusCode($response->status());
            } else {
                return inertia('UnauthenticatedErrorPage', ['status' => $response->status()])
                    ->toResponse($request)
                    ->setStatusCode($response->status());
            }
        } elseif ($response->status() === 419) {
            return back()->with([
                'message' => __('messages.expired'),
            ]);
        }

        return $response;
        // return parent::render($request, $exception);
    }
}
