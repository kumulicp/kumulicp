<?php

namespace App\Console\Calls;

use App\Application;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Notification;
use App\Notifications\NextcloudGroupFolderQuotaReached;
use App\Support\ByteConversion;
use App\Support\Facades\Organization;

class NextcloudStorageChecks
{
    public function __invoke()
    {
        $application = Application::where('slug', 'nextcloud')->first();
        $convert = new ByteConversion;

        foreach ($application->instances()->with('organization')->where('status', 'active')->get() as $app_instance) {
            Organization::setOrganization($app_instance->organization);
            $send = false;
            $maxed_folders = [];

            try {
                $group_folders = (new GroupFolders($app_instance))->all();

                foreach ($group_folders as $folder) {
                    $quota = (int) $folder->quota;
                    $size = (int) $folder->size;
                    $percent = (($size != 0 && $quota != 0) ? $size / $quota : 0) * 100;
                    $available = $quota - $size;
                    $convert_available = $convert($quota - $size, 'b', 'gb', 'byte') > 1 ? $convert($quota - $size, 'b', 'gb') : $convert($quota - $size, 'b', 'mb');
                    $name = (string) $folder->mount_point;
                    $past_notifications = Notification::withTrashed()->where('data->app_instance_name', $app_instance->name)->where('data->notification_name', 'nextcloud_group_folder_quota_reached')->whereJsonContains('data->folder_names', $name)->where('created_at', '>', now()->subDays(60))->count(); // TODO: Optimize to reduce queries

                    if ($percent > 90 && $past_notifications === 0) {
                        $maxed_folders[] = [
                            'name' => $name,
                            'percent' => $percent,
                            'size' => $convert($size, 'b', 'gb'),
                            'quota' => $convert($quota, 'b', 'gb'),
                            'available' => $convert_available,
                        ];

                        $send = true;
                    }
                }

                if ($send) {
                    $app_instance->organization->notifyAdmins(new NextcloudGroupFolderQuotaReached($app_instance->organization, $app_instance, $maxed_folders));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
