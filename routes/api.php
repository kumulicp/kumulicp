<?php

use App\Http\Controllers\API\Account\Users;
use App\Http\Controllers\API\Backups;
use App\Http\Controllers\API\Dkim;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php under the /api prefix.
|
*/

/*
 * Legacy unversioned endpoints, authenticated with the organization api_token.
 * The DKIM and backup routes are called back by Kubernetes jobs that may
 * already be running with these URLs baked in, so their paths must not change.
 * They are replaced under /api/v1 in later stages.
 */
Route::middleware('auth:api')->group(function () {
    Route::get('users', [Users::class, 'list']);
    Route::post('users', [Users::class, 'store']);
    Route::post('backup/update', [Backups::class, 'update']);
    Route::post('dkim/{job_id}', [Dkim::class, 'update']);
});

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware('api.v1')
    ->group(base_path('routes/api/v1.php'));
