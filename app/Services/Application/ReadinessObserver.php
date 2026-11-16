<?php

namespace App\Services\Application;

use App\Application;
use App\Server;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps an app's saved status in step with whether it is ready, whenever
 * something it depends on changes: one of its versions or plans, or a server.
 *
 * Changes that skip model events (a query-builder update, for one) are caught
 * by the scheduled SyncApplicationReadiness call.
 */
class ReadinessObserver
{
    public function saved(Model $model): void
    {
        $this->sync($model);
    }

    public function deleted(Model $model): void
    {
        $this->sync($model);
    }

    private function sync(Model $model): void
    {
        // A server can be where any app runs
        if ($model instanceof Server) {
            ApplicationReadiness::syncAll();

            return;
        }

        // A version or a plan belongs to one app
        if ($app = Application::find($model->application_id)) {
            (new ApplicationReadiness($app))->sync();
        }
    }
}
