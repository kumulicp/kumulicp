<?php

namespace App\Logging\Processors;

use App\Support\Redactor;
use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Prepares a record for shipping to a log collector (Loki / Alloy):
 *  - stamps the service, environment, process type and host
 *  - copies Laravel's shared Context (request_id, organization_id, task_id, ...) into `extra`
 *  - masks values whose key looks secret and truncates very long strings
 */
class StructuredLogProcessor implements ProcessorInterface
{
    private const MAX_STRING_LENGTH = 2000;

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = array_merge($record->extra, $this->sharedContext(), [
            'service' => config('app.name'),
            'env' => config('app.env'),
            'process' => config('logging.process') ?: (app()->runningInConsole() ? 'cli' : 'web'),
            'host' => gethostname() ?: null,
            'release' => config('logging.release'),
            'event' => $this->event($record),
        ]);

        return $record->with(
            context: $this->clean($record->context),
            extra: $this->clean(array_filter($extra, fn ($value) => $value !== null && $value !== '')),
        );
    }

    /**
     * Stable, locale-independent name for the record: an explicit `event` context key, or the
     * message itself when it is already a dotted identifier such as "task.failed".
     */
    private function event(LogRecord $record): ?string
    {
        $event = $record->context['event'] ?? null;

        if (is_string($event) && $event !== '') {
            return $event;
        }

        return preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $record->message) ? $record->message : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedContext(): array
    {
        try {
            return Context::all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function clean(array $values): array
    {
        $redacted = Redactor::redact($values);

        return $this->truncate($redacted);
    }

    private function truncate(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->truncate($item), $value);
        }

        if (is_string($value) && mb_strlen($value) > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH).'… [truncated]';
        }

        return $value;
    }
}
