<?php

namespace App\Logging\Concerns;

use App\Support\Redactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Throwable;

/**
 * Shared helpers for the custom Monolog handlers (MySQL, email, Matrix).
 *
 * Keeps record parsing in one place so a record without an exception, without
 * an organization or logged from the console can never blow up a handler.
 */
trait InspectsLogRecords
{
    protected const TRACE_LIMIT = 4000;

    protected const ALERT_DEDUPE_SECONDS = 300;

    protected function recordException(LogRecord $record): ?Throwable
    {
        $exception = $record->context['exception'] ?? null;

        return $exception instanceof Throwable ? $exception : null;
    }

    /**
     * Organization the record belongs to: explicit context first, then the
     * shared Context (set per request / queued job).
     */
    protected function recordOrganizationId(LogRecord $record): ?int
    {
        $organization_id = $record->context['organization_id'] ?? null;

        if (! is_numeric($organization_id)) {
            try {
                $organization_id = Context::get('organization_id');
            } catch (Throwable) {
                $organization_id = null;
            }
        }

        return is_numeric($organization_id) ? (int) $organization_id : null;
    }

    /**
     * @return array{remote_addr: string, user_agent: string}
     */
    protected function requestOrigin(): array
    {
        if (app()->runningInConsole()) {
            return ['remote_addr' => '', 'user_agent' => ''];
        }

        $request = request();

        return [
            'remote_addr' => (string) $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ];
    }

    /**
     * Context without the exception object, with secrets masked, safe to JSON encode or queue.
     *
     * @return array<string, mixed>
     */
    protected function recordContext(LogRecord $record): array
    {
        $context = $record->context;
        unset($context['exception']);

        $exception = $this->recordException($record);
        if ($exception) {
            $context['exception'] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->recordTrace($record),
            ];
        }

        return Redactor::redact($context);
    }

    /**
     * Trace as a trimmed string (no argument values), empty when the record has no exception.
     */
    protected function recordTrace(LogRecord $record): string
    {
        $exception = $this->recordException($record);

        if (! $exception) {
            return '';
        }

        return mb_substr($exception->getTraceAsString(), 0, self::TRACE_LIMIT);
    }

    protected function encodeJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * True when an identical alert was already sent in the last few minutes.
     * Stops a failing upstream (retried every minute) from flooding email / chat.
     */
    protected function isDuplicateAlert(LogRecord $record, string $prefix): bool
    {
        $exception = $this->recordException($record);
        $signature = $exception
            ? $exception::class.'|'.$exception->getFile().'|'.$exception->getLine().'|'.$exception->getMessage()
            : $record->message;

        try {
            return ! Cache::add(
                'logalert:'.$prefix.':'.md5($record->channel.'|'.$record->level->value.'|'.$signature),
                1,
                self::ALERT_DEDUPE_SECONDS
            );
        } catch (Throwable) {
            return false;
        }
    }
}
