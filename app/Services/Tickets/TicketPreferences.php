<?php

namespace App\Services\Tickets;

use App\User;

/**
 * Which ticket notifications each person wants. Everything is on until they
 * switch it off. Stored on the user as {event: {mail: bool, panel: bool}}.
 */
class TicketPreferences
{
    /** Events a person can switch off. */
    public const EVENTS = ['created', 'reply', 'note', 'status', 'assigned', 'assignee_removed'];

    public const CHANNELS = ['mail', 'panel'];

    /** Internal notes are staff-only, so customers are not offered that switch. */
    public const STAFF_ONLY = ['note'];

    /**
     * The full matrix with defaults filled in.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(User $user): array
    {
        $stored = $user->ticket_preferences ?? [];
        $matrix = [];

        foreach (self::EVENTS as $event) {
            foreach (self::CHANNELS as $channel) {
                $matrix[$event][$channel] = (bool) ($stored[$event][$channel] ?? true);
            }
        }

        return $matrix;
    }

    public function allows(User $user, string $event, string $channel): bool
    {
        return (bool) ($user->ticket_preferences[$event][$channel] ?? true);
    }

    /**
     * Save a submitted matrix. Unknown events and channels are ignored.
     *
     * @param  array<string, array<string, mixed>>  $input
     */
    public function update(User $user, array $input): void
    {
        $matrix = $this->matrix($user);

        foreach (self::EVENTS as $event) {
            foreach (self::CHANNELS as $channel) {
                if (isset($input[$event]) && array_key_exists($channel, $input[$event])) {
                    $matrix[$event][$channel] = filter_var($input[$event][$channel], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        $user->ticket_preferences = $matrix;
        $user->save();
    }

    /**
     * The events to offer this person.
     *
     * @return list<string>
     */
    public function eventsFor(bool $staff): array
    {
        return array_values(array_filter(self::EVENTS, fn (string $event) => $staff || ! in_array($event, self::STAFF_ONLY, true)));
    }
}
