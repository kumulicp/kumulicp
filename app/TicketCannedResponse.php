<?php

namespace App;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable reply for staff, with {{variables}} filled in when it is used.
 *
 * @property int $id
 * @property string $title
 * @property string $body_html
 * @property string $scope shared|personal
 * @property int|null $owner_id
 */
class TicketCannedResponse extends Model
{
    public const SHARED = 'shared';

    public const PERSONAL = 'personal';

    protected $table = 'ticket_canned_responses';

    protected $fillable = ['title'];

    /**
     * What a staff member may use: everything shared plus their own.
     *
     * @param  Builder<TicketCannedResponse>  $query
     * @return Builder<TicketCannedResponse>
     */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('scope', self::SHARED)->orWhere('owner_id', $user->id);
        });
    }

    public function canBeChangedBy(User $user): bool
    {
        return $this->scope === self::SHARED || $this->owner_id === $user->id;
    }
}
