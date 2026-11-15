<?php

namespace App\Http\Api;

use App\Http\Middleware\Api\AddRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception raised under /api/* in one JSON shape:
 *
 *   { "message": "...", "code": "machine_readable", "request_id": "...", "errors": { field: [..] } }
 *
 * `message` and `errors` match Laravel's own validation format, so clients
 * (including our Vue forms) that already read those keys keep working. `code`
 * is the stable value clients should branch on; `message` is for humans.
 */
class ApiExceptionRenderer
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'gone',
        415 => 'unsupported_media_type',
        419 => 'session_expired',
        422 => 'unprocessable',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/*');
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! static::appliesTo($request)) {
            return null;
        }

        $headers = [AddRequestId::HEADER => AddRequestId::resolve($request)];
        $errors = null;

        if ($e instanceof ValidationException) {
            $status = 422;
            $code = 'validation_failed';
            $message = $e->getMessage();
            $errors = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $code = 'unauthenticated';
            $message = 'Unauthenticated.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::CODES[$status] ?? 'http_'.$status;
            $headers += $e->getHeaders();
            $message = $this->httpMessage($e, $status);
        } else {
            $status = 500;
            $code = 'server_error';
            $message = config('app.debug') ? $e->getMessage() : 'Server error.';
        }

        $body = ['message' => $message, 'code' => $code, 'request_id' => $headers[AddRequestId::HEADER]];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, $headers);
    }

    private function httpMessage(HttpExceptionInterface $e, int $status): string
    {
        // Route-binding failures carry the model class and key in their
        // message; 404s get a fixed message so internals are never exposed.
        if ($status === 404) {
            return 'Resource not found.';
        }

        if ($status >= 500 && ! config('app.debug')) {
            return 'Server error.';
        }

        return $e->getMessage() !== '' ? $e->getMessage() : (self::CODES[$status] ?? 'Error.');
    }
}
