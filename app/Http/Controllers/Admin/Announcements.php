<?php

namespace App\Http\Controllers\Admin;

use App\Announcement;
use App\Application;
use App\Http\Controllers\Controller;
use App\Notifications\NewAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Announcements extends Controller
{
    public function index()
    {
        $announcements = Announcement::all();
        $apps = Application::all();

        return inertia()->render('Admin/Announcements/AnnouncementsList', [
            'announcements' => $announcements->map(function ($announcement) {
                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'short_description' => $announcement->short_description,
                ];
            }),
            'apps' => $apps->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                ];
            })->push([
                'id' => 0,
                'name' => 'Control Panel',
                'slug' => 'control_panel',
            ]),
            'breadcrumbs' => [
                [
                    'label' => __('admin.announcements.announcements'),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        /* Validate */
        $validatedData = $request->validate([
            'title' => 'required|max:255',
        ]);

        $announcement = new Announcement;
        $announcement->title = $validatedData['title'];
        $announcement->description = $validatedData['title'];
        $announcement->save();

        return redirect('/admin/service/announcements/'.$announcement->id.'/edit')->with('success', __('admin.announcements.created'));
    }

    public function edit($id)
    {
        $announcement = Announcement::where('id', $id)->first();
        $apps = Application::all();

        return inertia()->render('Admin/Announcements/AnnouncementEdit', [
            'apps' => $apps->map(function ($app) {
                return [
                    'id' => $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                ];
            }),
            'announcement' => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'description' => $announcement->description,
                'short_description' => $announcement->short_description,
                'apps' => $announcement->affected_apps,
            ],
            'breadcrumbs' => [
                [
                    'url' => '/admin/service/announcements',
                    'label' => __('admin.announcements.announcements'),
                ],
                [
                    'label' => $announcement->title,
                ],
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        /* Validate */
        $validatedData = $request->validate([
            'title' => 'required|max:255',
            'apps' => 'nullable|array',
            'short_description' => 'required',
            'description' => 'required',
        ]);

        // Append data to announcement table
        $announcement = Announcement::where('id', $id)->first();
        $announcement->title = $validatedData['title'];
        $announcement->affected_apps = Arr::get($validatedData, 'apps', []);
        $announcement->short_description = $validatedData['short_description'];
        $announcement->description = $validatedData['description'];
        $announcement->update();

        return back()->with('success', __('admin.announcements.updated'));
    }

    public function destroy(Request $request, $id)
    {
        $announcement = Announcement::where('id', $id)->first();
        $announcement->delete();

        return redirect('/admin/service/announcements')->with('success', __('admin.announcements.deleted'));
    }

    public function archive() {}

    public function notify($id)
    {
        $announcement = Announcement::where('id', $id)->first();

        $affected = json_decode($announcement->affected_apps, true);
        foreach ($affected['affected'] as $app) {
            $application = Application::where('name', $app)->first();

            if ($application) {
                foreach ($application->organizations as $organization) {
                    $organization->notifyAdmins(new NewAnnouncement($announcement));
                }
            }
        }

        return redirect('/admin/server/announcements')->with('success', __('admin.announcements.emailed'));
    }
}
