<?php

namespace App;

use App\Enums\TicketImpact;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $number
 * @property int $organization_id
 * @property int|null $requester_id
 * @property int|null $customer_assignee_id
 * @property int|null $support_assignee_id
 * @property int|null $category_id
 * @property int|null $app_instance_id
 * @property string $subject
 * @property TicketType $type
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketImpact $impact
 * @property string $source
 * @property Carbon|null $first_response_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $last_requester_reply_at
 * @property Carbon|null $last_staff_reply_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property int $lock_version
 * @property-read string $reference
 * @property-read Organization $organization
 * @property-read User|null $requester
 * @property-read User|null $customerAssignee
 * @property-read User|null $supportAssignee
 * @property-read TicketCategory|null $category
 * @property-read AppInstance|null $appInstance
 */
class Ticket extends Model
{
    use SoftDeletes;

    protected $table = 'tickets';

    protected $fillable = [
        'subject', 'type', 'impact',
    ];

    protected $casts = [
        'type' => TicketType::class,
        'status' => TicketStatus::class,
        'priority' => TicketPriority::class,
        'impact' => TicketImpact::class,
        'first_response_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'last_requester_reply_at' => 'datetime',
        'last_staff_reply_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Tickets are addressed by their human number in URLs (/support/tickets/1042).
     */
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function getReferenceAttribute(): string
    {
        return config('tickets.prefix').'-'.$this->number;
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->id : $organization);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customerAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_assignee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function supportAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_assignee_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TicketCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<AppInstance, $this>
     */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class, 'app_instance_id');
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /**
     * Messages a customer is allowed to see.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('visibility', TicketMessage::PUBLIC);
    }

    /**
     * @return HasMany<TicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<TicketSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TicketSubscription::class);
    }

    /**
     * @return BelongsToMany<TicketTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TicketTag::class, 'ticket_ticket_tag');
    }
}
