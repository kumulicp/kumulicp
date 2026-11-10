<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An edit to a plan, version or role that app instances depend on. See
 * App\Updates\Detection\DefinitionChanges.
 *
 * @property int $id
 * @property class-string $subject_type
 * @property int $subject_id
 * @property string $subject_label
 * @property int|null $application_id
 * @property string $action created, updated or deleted
 * @property array<int, string> $changed
 * @property int|null $user_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class DefinitionChange extends Model
{
    protected $table = 'definition_changes';

    protected $guarded = [];

    protected $casts = [
        'changed' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
