<?php

namespace App\Support\Facades;

use App\AuditLog;
use App\Services\AuditService;
use App\Task;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static void forgetEnabledCache()
 * @method static AuditLog|null record(string $event, Model|array|null $subject = null, array $diff = [], array $properties = [], string $outcome = 'success', ?Authenticatable $actor = null, ?int $target_organization_id = null, bool $force = false)
 * @method static AuditLog|null recordTaskEvent(string $event, Task $task, array $properties = [], string $outcome = 'success')
 * @method static array|null initiator()
 * @method static void recordSettingChange(string $key, ?string $old, ?string $new)
 * @method static void recordModelEvent(string $action, Model $model)
 *
 * @see AuditService
 */
class Audit extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'audit';
    }
}
