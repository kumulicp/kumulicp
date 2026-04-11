<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Log;
use Illuminate\Support\Carbon;

class Logs extends Controller
{
    public function index()
    {
        $logs = Log::with('organization')->orderBy('created_at', 'desc')->paginate(30);

        return inertia()->render('Admin/LogsList', [
            'logs' => $logs->map(function ($log) {
                return [
                    'organization' => $log->organization?->name,
                    'level' => $log->level_name,
                    'message' => $log->message,
                    'time' => (new Carbon($log->created_at))->format('Y-m-d h:m'),
                ];
            }),
            'meta' => [
                'total' => $logs->total(),
                'pages' => $logs->lastPage(),
                'page' => $logs->currentPage(),
            ],
        ]);
    }
}
