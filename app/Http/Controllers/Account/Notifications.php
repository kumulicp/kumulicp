<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Notification;
use App\Support\Facades\FastCache;
use App\Support\Facades\Organization;
use App\Support\TaskHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Notifications extends Controller
{
    /**
     * This endpoint is polled from the UI every few seconds, so its result
     * is cached briefly per-user to avoid re-running the tasks and
     * notifications queries on every poll.
     */
    private const CACHE_TTL_SECONDS = 10;

    public function index()
    {
        $user = auth()->user();
        $organization = Organization::account();

        $response = FastCache::retrieve(
            $this->cacheKey($user),
            function () use ($organization, $user) {
                $getTasks = new TaskHelpers;
                $tasks = $getTasks->getGroupStatus(['organization_id' => $organization->id, 'background' => 0]);
                $notifications = Notification::where('notifiable_id', $user->username)
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

                return collect($tasks)->merge($notifications);
            },
            now()->addSeconds(self::CACHE_TTL_SECONDS)
        );

        return response()->json($response);
    }

    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'notifications' => 'required|array',
        ]);

        $user = auth()->user();
        Notification::where('notifiable_id', $user->username)
            ->whereIn('id', $validated['notifications'])
            ->delete();

        FastCache::clear($this->cacheKey($user));

        return response()->json([
            'status' => 'success',
        ]);
    }

    private function cacheKey($user): string
    {
        return "notifications:{$user->username}";
    }
}
