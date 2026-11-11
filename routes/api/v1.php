<?php

use App\Http\Controllers\API\V1\Ping;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Loaded from routes/api.php under the /api/v1 prefix with the "api.v1"
| middleware group (request id, JSON-only, rate limiting). Authentication is
| added in the next stage; until then only public routes belong here.
|
*/

Route::get('ping', Ping::class)->name('ping');
