<?php

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
$api = app('Dingo\Api\Routing\Router');

$api->version('v1', ['middleware' => 'auth:api'], function ($api) {
    $api->get('users', 'App\Http\Controllers\API\Account\Users@list');
    $api->post('users', 'App\Http\Controllers\API\Account\Users@store');
    $api->post('backup/update', 'App\Http\Controllers\API\Backups@update');
    $api->post('dkim/{job_id}', 'App\Http\Controllers\API\Dkim@update');
});
