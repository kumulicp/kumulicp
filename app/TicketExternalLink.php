<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int $integration_id
 * @property string $external_id
 * @property string|null $external_key
 * @property string|null $external_url
 * @property string|null $external_state
 * @property Carbon|null $last_synced_at
 * @property-read TicketIntegration $integration
 */
class TicketExternalLink extends Model
{
    protected $table = 'ticket_external_links';

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<TicketIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(TicketIntegration::class, 'integration_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
