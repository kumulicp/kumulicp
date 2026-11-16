<?php

namespace App\Services\Tickets;

use App\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A cheap "has anything changed?" token. Open pages poll with the token they
 * were rendered with; when it still matches, the server answers 304 from the
 * cache without touching the database.
 *
 * There is one token per ticket, one per organization (for its list) and one
 * overall (for the staff inbox). Every write to a ticket replaces all three.
 */
class TicketVersions
{
    public function ticket(Ticket|int $ticket): string
    {
        return $this->current('ticket:'.($ticket instanceof Ticket ? $ticket->id : $ticket));
    }

    public function organization(int $organizationId): string
    {
        return $this->current('org:'.$organizationId);
    }

    public function all(): string
    {
        return $this->current('all');
    }

    public function bump(Ticket $ticket): void
    {
        foreach (['ticket:'.$ticket->id, 'org:'.$ticket->organization_id, 'all'] as $key) {
            Cache::forever($this->key($key), $this->fresh());
        }
    }

    private function current(string $name): string
    {
        // If the cache lost the value everyone simply sees one extra change
        return Cache::rememberForever($this->key($name), fn () => $this->fresh());
    }

    private function key(string $name): string
    {
        return 'tickets:version:'.$name;
    }

    private function fresh(): string
    {
        return Str::random(12);
    }
}
