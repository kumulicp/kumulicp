<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configured connection to an outside system (a webhook, GitHub, GitLab...).
 *
 * @property int $id
 * @property string $driver
 * @property string $name
 * @property array $config Settings including secrets, encrypted at rest
 * @property bool $enabled
 * @property array|null $filters
 * @property array|null $events
 * @property array|null $options
 * @property int $consecutive_failures
 * @property string|null $disabled_reason
 * @property Carbon|null $last_success_at
 */
class TicketIntegration extends Model
{
    protected $table = 'ticket_integrations';

    protected $fillable = ['driver', 'name'];

    protected $casts = [
        'config' => 'encrypted:array',
        'enabled' => 'boolean',
        'filters' => 'array',
        'events' => 'array',
        'options' => 'array',
        'last_success_at' => 'datetime',
    ];

    /** Never serialize settings (they contain secrets) by accident. */
    protected $hidden = ['config'];

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /**
     * Does the ticket pass this integration's filters? An empty list allows anything.
     */
    public function matches(Ticket $ticket): bool
    {
        $filters = $this->filters ?? [];

        $allowed = fn (string $key, mixed $value): bool => empty($filters[$key]) || in_array($value, $filters[$key], false);

        return $allowed('types', $ticket->type->value)
            && $allowed('priorities', $ticket->priority->value)
            && $allowed('categories', $ticket->category_id);
    }

    /**
     * @return HasMany<TicketDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(TicketDelivery::class, 'integration_id');
    }

    /**
     * @return HasMany<TicketExternalLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class, 'integration_id');
    }
}
