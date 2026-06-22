<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrgUserRegistration extends Model
{
    protected $table = 'org_user_registrations';

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public static function generate(Organization $organization, string $email): static
    {
        static::where('organization_id', $organization->id)->where('email', $email)->delete();

        $record = new static;
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
