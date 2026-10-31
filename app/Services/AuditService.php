<?php

namespace App\Services;

use App\AuditLog;
use App\Organization;
use App\ServerSetting;
use App\Support\Redactor;
use App\Task;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The audit trail: who did what, to what, when and from where.
 *
 * Everything is a no-op until an administrator turns on the "audit_logging_enabled"
 * server setting. Entries are stored in the audit_logs table (append-only) and also
 * streamed as one JSON line to the configured log channel (config audit.channel) so
 * a collector like Alloy can keep an off-box copy.
 *
 * Recording never throws: a failed audit write is logged as `audit.write_failed`
 * and the request or job carries on.
 */
class AuditService
{
    public const SETTING_KEY = 'audit_logging_enabled';

    private const CACHE_KEY = 'audit.enabled';

    private const VALUE_LIMIT = 500;

    /**
     * Whether audit logging is on. Reads the setting straight from the database (cached
     * for a minute) rather than through Settings, which keeps its values in memory for
     * the life of a worker and would go stale under Octane and in queue workers.
     */
    public function enabled(): bool
    {
        try {
            return (bool) Cache::remember(self::CACHE_KEY, 60, fn () => (bool) ServerSetting::where('key', self::SETTING_KEY)->value('value'));
        } catch (Throwable) {
            return false;
        }
    }

    public function forgetEnabledCache(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Record one audit event.
     *
     * @param  string  $event  dotted name, e.g. "auth.login" or "user.created"
     * @param  Model|array{type?: string, id?: mixed, label?: string}|null  $subject  what the event happened to
     * @param  array<string, mixed>  $diff  attribute => ['old' => ..., 'new' => ...]
     * @param  array<string, mixed>  $properties  anything else worth keeping (never request bodies or secrets)
     * @param  string  $outcome  success, failure or denied
     * @param  Authenticatable|null  $actor  defaults to the authenticated user
     * @param  bool  $force  record even when audit logging is off (used for the toggle itself)
     */
    public function record(
        string $event,
        Model|array|null $subject = null,
        array $diff = [],
        array $properties = [],
        string $outcome = 'success',
        ?Authenticatable $actor = null,
        ?int $target_organization_id = null,
        bool $force = false,
    ): ?AuditLog {
        if (! $force && ! $this->enabled()) {
            return null;
        }

        return $this->write($event, $subject, $diff, $properties, $outcome, $this->describeActor($actor), $target_organization_id);
    }

    /**
     * A task event ("task.created", "task.completed", "task.failed"). The actor is whoever
     * triggered the task, stored on the task row, because by the time a task completes
     * (queue worker, scheduler) nobody is logged in. Tasks get deleted, so the facts are
     * copied here.
     *
     * @param  array<string, mixed>  $properties
     */
    public function recordTaskEvent(string $event, Task $task, array $properties = [], string $outcome = 'success'): ?AuditLog
    {
        if (! $this->enabled()) {
            return null;
        }

        $actor = $task->initiated_by_id
            ? ['type' => (string) $task->initiated_by_type, 'id' => $this->numeric($task->initiated_by_id), 'label' => null, 'organization_id' => null]
            : ['type' => 'system', 'id' => null, 'label' => null, 'organization_id' => null];

        return $this->write(
            $event,
            $task,
            [],
            ['action_slug' => $task->action_slug, 'description' => $task->description, 'attempts' => $task->attempts] + $properties,
            $outcome,
            $actor,
            $this->numeric($task->organization_id),
        );
    }

    /**
     * Who is acting right now, as stored on a task: ['type' => 'user', 'id' => 12], or null if nobody is.
     *
     * @return array{type: string, id: int}|null
     */
    public function initiator(): ?array
    {
        $actor = $this->describeActor(null);

        return $actor['id'] ? ['type' => $actor['type'], 'id' => $actor['id']] : null;
    }

    /**
     * @param  Model|array<string, mixed>|null  $subject
     * @param  array<string, mixed>  $diff
     * @param  array<string, mixed>  $properties
     * @param  array{type: string, id: int|null, label: string|null, organization_id: int|null}  $actor_data
     */
    private function write(string $event, Model|array|null $subject, array $diff, array $properties, string $outcome, array $actor_data, ?int $target_organization_id): ?AuditLog
    {
        try {
            [$subject_type, $subject_id, $subject_label] = $this->describeSubject($subject);

            $entry = AuditLog::create([
                'event_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'event' => $event,
                'outcome' => $outcome,
                'actor_type' => $actor_data['type'],
                'actor_id' => $actor_data['id'],
                'actor_label' => $actor_data['label'],
                'actor_organization_id' => $actor_data['organization_id'],
                'target_organization_id' => $target_organization_id ?? $this->targetOrganizationId($subject),
                'subject_type' => $subject_type,
                'subject_id' => $subject_id,
                'subject_label' => $subject_label,
                'diff' => $diff === [] ? null : $this->clean($diff),
                'properties' => $properties === [] ? null : $this->clean($properties),
                'ip' => $this->isHttp() ? request()->ip() : null,
                'user_agent' => $this->isHttp() ? mb_substr((string) request()->userAgent(), 0, 255) : null,
                'request_id' => Context::get('request_id'),
                'route' => Context::get('route'),
                'source' => $this->source(),
                'task_id' => $this->numeric(Context::get('task_id')),
            ]);

            $this->stream($entry);

            return $entry;
        } catch (Throwable $e) {
            Log::error('Audit entry could not be written', [
                'event' => 'audit.write_failed',
                'audit_event' => $event,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * A server setting changed. The change to the audit setting itself is always recorded
     * when either side is "on", so turning auditing on or off can never go unnoticed.
     */
    public function recordSettingChange(string $key, ?string $old, ?string $new): void
    {
        $is_audit_setting = $key === self::SETTING_KEY;

        if ($is_audit_setting) {
            $this->forgetEnabledCache();
        }

        if ($old === $new) {
            return;
        }

        $sensitive = $this->isSensitiveKey($key);

        $this->record(
            $is_audit_setting ? ($new ? 'audit.enabled' : 'audit.disabled') : 'settings.changed',
            ['type' => 'server_setting', 'id' => $key, 'label' => $key],
            [$key => [
                'old' => $sensitive ? ($old === null ? null : Redactor::redact([$key => $old])[$key]) : $old,
                'new' => $sensitive ? ($new === null ? null : Redactor::redact([$key => $new])[$key]) : $new,
            ]],
            force: $is_audit_setting && ($old || $new),
        );
    }

    /**
     * An Eloquent model event ("created", "updated" or "deleted") for one of the configured models.
     */
    public function recordModelEvent(string $action, Model $model): void
    {
        if (! in_array($model::class, config('audit.models', []), true) || ! $this->enabled()) {
            return;
        }

        $diff = [];

        if ($action === 'updated') {
            foreach (Arr::except($model->getChanges(), [$model->getUpdatedAtColumn()]) as $attribute => $new) {
                $diff[$attribute] = ['old' => $model->getOriginal($attribute), 'new' => $new];
            }

            if ($diff === []) {
                return;
            }
        } else {
            $side = $action === 'created' ? 'new' : 'old';

            foreach ($model->getAttributes() as $attribute => $value) {
                $diff[$attribute] = [$side => $value];
            }
        }

        $hidden = $model->getHidden();

        foreach ($diff as $attribute => $values) {
            if (in_array($attribute, $hidden, true) || $this->isSensitiveKey($attribute)) {
                $diff[$attribute] = array_map(fn () => '[hidden]', $values);
            }
        }

        $this->record(Str::snake(class_basename($model)).'.'.$action, $model, $diff);
    }

    /**
     * @return array{type: string, id: int|null, label: string|null, organization_id: int|null}
     */
    private function describeActor(?Authenticatable $actor): array
    {
        $actor ??= $this->authenticatedUser();

        if ($actor) {
            $organization = $actor instanceof Organization ? $actor : ($actor->organization ?? null);
            $key = $actor->getAuthIdentifier();

            return [
                'type' => $actor instanceof Organization ? 'organization' : 'user',
                'id' => $this->numeric($key),
                'label' => mb_substr((string) ($actor->email ?? $actor->name ?? $key), 0, 255),
                'organization_id' => is_object($organization) ? $this->numeric($organization->id ?? null) : null,
            ];
        }

        return [
            'type' => $this->isHttp() ? 'anonymous' : 'system',
            'id' => null,
            'label' => null,
            'organization_id' => null,
        ];
    }

    private function authenticatedUser(): ?Authenticatable
    {
        try {
            return Auth::user();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function describeSubject(Model|array|null $subject): array
    {
        if ($subject instanceof Model) {
            $label = $subject->getAttribute('name') ?? $subject->getAttribute('email') ?? $subject->getAttribute('slug');

            return [$subject::class, (string) $subject->getKey(), is_scalar($label) ? mb_substr((string) $label, 0, 255) : null];
        }

        if (is_array($subject)) {
            return [
                $subject['type'] ?? null,
                isset($subject['id']) ? mb_substr((string) $subject['id'], 0, 100) : null,
                isset($subject['label']) ? mb_substr((string) $subject['label'], 0, 255) : null,
            ];
        }

        return [null, null, null];
    }

    private function targetOrganizationId(Model|array|null $subject): ?int
    {
        if ($subject instanceof Organization) {
            return $this->numeric($subject->getKey());
        }

        if ($subject instanceof Model) {
            return $this->numeric($subject->getAttribute('organization_id'));
        }

        return null;
    }

    private function isHttp(): bool
    {
        return ! app()->runningInConsole() || app()->runningUnitTests();
    }

    private function source(): string
    {
        if ($this->isHttp() && app()->bound('request')) {
            return request()->is('api/*') ? 'api' : 'web';
        }

        if (Context::get('task_id')) {
            return 'queue';
        }

        return config('logging.process') === 'scheduler' ? 'scheduler' : 'cli';
    }

    private function numeric(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function isSensitiveKey(string $key): bool
    {
        return Redactor::redact([$key => 'x'])[$key] !== 'x';
    }

    /**
     * Mask secrets and flatten values so the JSON column stays small and readable.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function clean(array $values): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->clean($value);
            }

            if (is_object($value) || is_resource($value)) {
                $value = $value instanceof \Stringable || $value instanceof \DateTimeInterface
                    ? ($value instanceof \DateTimeInterface ? $value->format('c') : (string) $value)
                    : '['.get_debug_type($value).']';
            }

            return is_string($value) && mb_strlen($value) > self::VALUE_LIMIT
                ? mb_substr($value, 0, self::VALUE_LIMIT).'… [truncated]'
                : $value;
        }, Redactor::redact($values));
    }

    private function stream(AuditLog $entry): void
    {
        $channel = config('audit.channel');

        if (! $channel || $channel === 'null') {
            return;
        }

        try {
            Log::channel($channel)->info('audit.'.$entry->event, [
                'event' => 'audit.'.$entry->event,
                'event_id' => $entry->event_id,
                'outcome' => $entry->outcome,
                'actor' => ['type' => $entry->actor_type, 'id' => $entry->actor_id, 'label' => $entry->actor_label, 'organization_id' => $entry->actor_organization_id],
                'target_organization_id' => $entry->target_organization_id,
                'subject' => ['type' => $entry->subject_type, 'id' => $entry->subject_id, 'label' => $entry->subject_label],
                'diff' => $entry->diff,
                'properties' => $entry->properties,
                'ip' => $entry->ip,
                'route' => $entry->route,
                'source' => $entry->source,
            ]);
        } catch (Throwable) {
            // The database row is the record of truth; a missing stream line must not fail the caller.
        }
    }
}
