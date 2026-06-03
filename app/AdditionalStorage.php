<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $app_instance_id
 * @property string $entity
 * @property string $name
 * @property string|null $application
 * @property int $quantity
 */
class AdditionalStorage extends Model
{
    use HasFactory;

    protected $table = 'additional_storage';
}
