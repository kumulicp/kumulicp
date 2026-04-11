<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Notification;
use App\Support\Facades\Organization;
use App\Support\TaskHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Notifications extends Controller
{
    public function index()
    {
        $organization = Organization::account();

        $getTasks = new TaskHelpers;
        $tasks = $getTasks->getGroupStatus(['organization_id' => $organization->id, 'background' => 0]);
        $notifications = Notification::where('notifiable_id', auth()->user()->username)
            ->where('created_at', '>', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => Arr::get($notification->data, 'title'),
                    'description' => Arr::get($notification->data, 'message'),
                    'unread' => is_null($notification->read_at),
                    'status' => 'Complete',
                    'type' => 'notification',
                ];
            });

        $response = collect($tasks)->merge($notifications);

        return response()->json($response);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'notifications' => 'required|array',
        ]);

        $user = auth()->user();
        $notification = Notification::where('notifiable_id', $user->username)
            ->whereIn('id', $validated['notifications'])
            ->delete();

        return response()->json([
            'status' => 'success',
        ]);
    }
}
