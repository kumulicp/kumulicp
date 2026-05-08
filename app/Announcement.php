<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $table = 'announcements';

    protected $fillable = [
        'title',
        'short_description',
        'description',
        'tags',
        'affected_apps',
    ];

    protected $casts = [
        'tags' => 'array',
        'affected_apps' => 'array',
    ];

    public function affected_apps()
    {
        $affected_apps = json_decode($this->affected_apps);

        foreach ($affected_apps->affected as $affected_app) {
            $apps[] = Application::where('slug', $affected_app)->first();
        }

        return $apps;
    }
}
