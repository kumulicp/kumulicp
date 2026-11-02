<?php

namespace App\Services\Tickets;

/**
 * Reads the ways people write a length of time and says how many minutes it is.
 *
 *   90  90m  90 min  1h  1h30  1h 30m  1.5h  1,5h  1:30  2 hours 15 minutes
 */
final class DurationParser
{
    public static function parse(string $input): ?int
    {
        $text = strtolower(trim($input));

        if ($text === '') {
            return null;
        }

        // Plain number: minutes
        if (preg_match('/^\d+$/', $text)) {
            return (int) $text;
        }

        // 1:30
        if (preg_match('/^(\d+):([0-5]?\d)$/', $text, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        // 1.5h / 1,5 hours
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:h|hr|hrs|hour|hours)$/', $text, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]) * 60);
        }

        // 1h30 / 1h 30m / 2 hours 15 minutes / 45m
        if (preg_match('/^(?:(\d+)\s*(?:h|hr|hrs|hour|hours))?\s*(?:(\d+)\s*(?:m|min|mins|minute|minutes)?)?$/', $text, $m) && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')) {
            return (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0);
        }

        return null;
    }

    /**
     * 90 -> "1h 30m"
     */
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours > 0 && $rest > 0 => "{$hours}h {$rest}m",
            $hours > 0 => "{$hours}h",
            default => "{$rest}m",
        };
    }

    /**
     * 90 -> "1h30m", the form GitLab's time tracking reads.
     */
    public static function compact(int $minutes): string
    {
        return str_replace(' ', '', self::format($minutes));
    }
}
