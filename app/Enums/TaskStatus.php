<?php

namespace App\Enums;

enum TaskStatus: string
{
    case PENDING = 'pending';

    case READY = 'ready';

    case QUEUED = 'queued';

    case IN_PROGRESS = 'in_progress';

    case COMPLETE = 'complete';

    case FAILED = 'failed';

    // Waiting on a task it depends on that has failed
    case BLOCKED = 'blocked';

    /**
     * Not finished. A failed or blocked task counts as open: it still blocks
     * identical tasks and anything waiting on it until it is retried or removed.
     */
    public function isOpen(): bool
    {
        return $this !== self::COMPLETE;
    }

    /**
     * Status values of every open status, for queries.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOpen())
        ));
    }

    /**
     * The moves tasks make today, taken from how the actions behave. Used to
     * flag (not block) a status change nothing is known to make.
     *
     * @return array<int, self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::PENDING => [self::READY, self::IN_PROGRESS, self::COMPLETE, self::FAILED, self::BLOCKED],
            self::READY => [self::QUEUED, self::IN_PROGRESS, self::PENDING, self::FAILED],
            self::QUEUED => [self::IN_PROGRESS, self::READY, self::PENDING, self::FAILED],
            self::IN_PROGRESS => [self::COMPLETE, self::FAILED, self::PENDING, self::READY, self::BLOCKED],
            self::FAILED => [self::PENDING, self::READY],
            self::BLOCKED => [self::PENDING, self::IN_PROGRESS, self::FAILED],
            self::COMPLETE => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this === $status || in_array($status, $this->transitions(), true);
    }
}
