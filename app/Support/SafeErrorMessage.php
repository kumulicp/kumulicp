<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Exception text is written for logs, not users: a QueryException message
 * carries the full SQL, every bound value (encrypted passwords, settings
 * JSON), and the database host, port and name. Anything that ends up in an
 * Inertia prop, flash message or JSON response goes through here first; the
 * original text stays in the logs and in stored task records.
 */
class SafeErrorMessage
{
    // Matches the driver prefix PDO puts on every database error message
    private const DATABASE_ERROR_PATTERN = '/SQLSTATE\[|\(Connection: [^,]+, Host:|\bSQL: /';

    public static function from(Throwable $exception): string
    {
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return self::generic();
        }

        return self::scrub($exception->getMessage()) ?? '';
    }

    // The message handed to the Inertia error pages. Only text the developer
    // wrote for the user (abort(403, '...')) is sent; 5xx and 404 messages
    // come from the framework or a driver (SQL, model class names, hosts).
    public static function forErrorPage(Throwable $exception, int $status): ?string
    {
        if ($status >= 500 || $status === 404 || ! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        return self::scrub($exception->getMessage()) ?: null;
    }

    // For text that was stored from an exception earlier (e.g. a task's error_message)
    public static function scrub(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        return preg_match(self::DATABASE_ERROR_PATTERN, $message) ? self::generic() : $message;
    }

    public static function isDatabaseError(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            || $exception instanceof PDOException
            || (bool) preg_match(self::DATABASE_ERROR_PATTERN, $exception->getMessage());
    }

    private static function generic(): string
    {
        return __('messages.exception.database_error');
    }
}
