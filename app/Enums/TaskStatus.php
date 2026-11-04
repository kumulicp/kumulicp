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

    // Stopped by an admin before it finished
    case CANCELLED = 'cancelled';

    /**
     * Not finished. A failed or blocked task counts as open: it still blocks
     * identical tasks and anything waiting on it until it is retried or removed.
     * A cancelled one doesn't: it was given up on on purpose (what waited on it
     * is blocked, though).
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::COMPLETE, self::CANCELLED], true);
    }

    /**
     * Whether an admin can retry a task in this status.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::FAILED, self::BLOCKED, self::CANCELLED], true);
    }

    /**
     * Whether an admin can stop a task in this status.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::PENDING, self::READY, self::QUEUED, self::IN_PROGRESS, self::BLOCKED], true);
    }

    /**
     * Whether an admin can delete a task in this status: only one that is over.
     * Deleting also releases whatever waited on it.
     */
    public function isDeletable(): bool
    {
        return in_array($this, [self::COMPLETE, self::FAILED, self::CANCELLED], true);
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
            self::PENDING => [self::READY, self::IN_PROGRESS, self::COMPLETE, self::FAILED, self::BLOCKED, self::CANCELLED],
            self::READY => [self::QUEUED, self::IN_PROGRESS, self::PENDING, self::FAILED, self::CANCELLED],
            self::QUEUED => [self::IN_PROGRESS, self::READY, self::PENDING, self::FAILED, self::CANCELLED],
            self::IN_PROGRESS => [self::COMPLETE, self::FAILED, self::PENDING, self::READY, self::BLOCKED, self::CANCELLED],
            self::FAILED => [self::PENDING, self::READY],
            self::BLOCKED => [self::PENDING, self::IN_PROGRESS, self::FAILED, self::CANCELLED],
            self::CANCELLED => [self::PENDING, self::READY],
            self::COMPLETE => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return $this === $status || in_array($status, $this->transitions(), true);
    }
}
