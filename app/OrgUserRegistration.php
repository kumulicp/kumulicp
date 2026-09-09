<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OrgUserRegistration extends Model
{
    protected $table = 'org_user_registrations';

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public static function generate(Organization $organization, string $email): self
    {
        self::where('organization_id', $organization->id)->where('email', $email)->delete();

        $record = new self;
        $record->organization_id = $organization->id;
        $record->email = $email;
        $record->token = Str::random(64);
        $record->expires_at = now()->addHour();
        $record->save();

        return $record;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
