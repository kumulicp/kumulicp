<?php

namespace App\Http\Controllers;

use App\Announcement;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Support\ByteConversion;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return Renderable
     */
    public function index()
    {
        if (Organization::account()->status === 'new') {
            return redirect('welcome');
        }

        $o = Organization::account()->slug;
        $date = new Carbon;
        $converter = new ByteConversion;
        $announcements = Announcement::orderBy('created_at', 'desc')->limit(5)->get();

        $folder_groups = null;
        $nextcloud = Organization::appByName('nextcloud');
        if ($nextcloud?->status === 'active') {
            try {
                $nextcloud_groups = (new GroupFolders($nextcloud))->all();

                foreach ($nextcloud_groups as $group) {
                    $folder_groups[] = [
                        'label' => (string) $group->mount_point,
                        'size' => $converter($group->size, 'b', 'gb'),
                        'quota' => $converter($group->quota, 'b', 'gb'),
                        'unit' => 'GB',
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $info_blocks = [];
        $count_users = Organization::countStandard();
        $info_blocks[] = [
            'label' => Str::plural('User', $count_users),
            'value' => $count_users,
        ];
        if ($basic_name = Organization::plan()->setting('basic.name')) {
            $count_basic = Organization::countBasic();
            $info_blocks[] = [
                'label' => Str::plural($basic_name, $count_basic),
                'value' => $count_basic,
            ];
        }

        $count_groups = Organization::countGroups();
        $info_blocks[] = [
            'label' => Str::plural('Group', $count_groups),
            'value' => $count_groups,
        ];

        $count_apps = Organization::countApplication();
        $info_blocks[] = [
            'label' => Str::plural('App', $count_apps),
            'value' => $count_apps,
        ];

        if ($count_email = Organization::countEmail()) {
            $info_blocks[] = [
                'label' => Str::plural('Email', $count_email),
                'value' => $count_email,
            ];
        }

        $plan = Organization::plan();

        return inertia('Organization/Dashboard/AccountDashboard', [
            'trial_plan' => $plan ? $plan->type == 'trial' : false,
            'info_blocks' => $info_blocks,
            'nextcloud_folders' => $folder_groups,
            'announcements' => $announcements->map(function ($announcement) {
                return [
                    'title' => $announcement->title,
                    'content' => $announcement->short_description,
                    'apps' => $announcement->affected_apps,
                    'link' => "/announcements/{$announcement->id}",
                ];
            }),
        ]);
    }

    public function welcome()
    {
        if (Organization::account()->status !== 'new') {
            return redirect('/');
        }

        $welcome_page = Settings::get('welcome_page');

        return inertia('Organization/Dashboard/WelcomeDashboard', [
            'page_content' => $welcome_page,
        ]);
    }
}
