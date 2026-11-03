<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $integration_id
 * @property int $ticket_id
 * @property string $event
 * @property string $idempotency_key
 * @property string $status pending|delivered|failed
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $error
 * @property array $payload
 * @property string|null $response
 * @property Carbon|null $delivered_at
 * @property Carbon|null $last_attempt_at
 * @property-read TicketIntegration $integration
 */
class TicketDelivery extends Model
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $table = 'ticket_deliveries';

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'last_attempt_at' => 'datetime',
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
