<?php

namespace App\Http\Controllers\Account;

use App\Announcement;
use App\Http\Controllers\Controller;
use Carbon\Carbon;

class Announcements extends Controller
{
    public function show($id)
    {
        $announcement = Announcement::where('id', $id)->first();

        return inertia('Organization/Announcements/AnnouncementView', [
            'announcement' => [
                'title' => $announcement->title,
                'content' => $announcement->description,
                'date' => (new Carbon)->parse($announcement->created_at)->isoFormat('LL'),
                'link' => "/announcements/{$announcement->id}",
            ],
        ]);
    }
}
