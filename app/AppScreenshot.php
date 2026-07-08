<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $application_id
 * @property string $filename
 * @property int $display_order
 * @property-read \App\Application $application
 */
class AppScreenshot extends Model
{
    use HasFactory;

    protected $table = 'app_screenshots';

    protected $fillable = [
        'application_id',
        'filename',
        'display_order',
    ];

    public function application()
    {
        return $this->belongsTo('App\Application', 'application_id');
    }

    public function getUrlAttribute()
    {
        return '/images/screenshots/'.$this->filename;
    }
}
