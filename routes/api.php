<?php

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Registered directly via the standard router (not Dingo) — this must stay
// outside the v1/auth:api group below since Stripe calls it unauthenticated.
// Signature verification is applied by Cashier's WebhookController itself.
//
// Dingo's LaravelServiceProvider re-executes this whole file a second time
// (raw, without Laravel's own `api` prefix/middleware group) to build its
// own router — guard against that duplicate pass overwriting this route's
// name with an unprefixed, unmiddlewared copy.
if (! Route::has('cashier.webhook')) {
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');
}

$api = app('Dingo\Api\Routing\Router');

$api->version('v1', ['middleware' => 'auth:api'], function ($api) {
    $api->get('users', 'App\Http\Controllers\API\Account\Users@list');
    $api->post('users', 'App\Http\Controllers\API\Account\Users@store');
    $api->post('backup/update', 'App\Http\Controllers\API\Backups@update');
    $api->post('dkim/{job_id}', 'App\Http\Controllers\API\Dkim@update');
});
