<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * How quickly tickets must be answered and resolved, by priority, and during
 * which hours the clock runs.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 * @property bool $is_default
 * @property array<string, array{first_response?: int|null, resolution?: int|null}> $targets Minutes
 * @property array|null $business_hours
 */
class TicketSlaPolicy extends Model
{
    protected $table = 'ticket_sla_policies';

    protected $fillable = ['name'];

    protected $casts = [
        'active' => 'boolean',
        'is_default' => 'boolean',
        'targets' => 'array',
        'business_hours' => 'array',
    ];

    /**
     * Minutes allowed for a clock at a priority, or null for no target.
     */
    public function target(string $priority, string $clock): ?int
    {
        $minutes = $this->targets[$priority][$clock] ?? null;

        return $minutes ? (int) $minutes : null;
    }
}
