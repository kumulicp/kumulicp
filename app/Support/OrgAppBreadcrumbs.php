<?php

namespace App\Support;

use App\AppInstance;
use App\Organization;

class OrgAppBreadcrumbs
{
    /**
     * An AppInstance whose plan is hidden is itself the canonical instance
     * for a shared app (see SharedApps::store()), so its breadcrumb links
     * back there instead of through the owning organization's app list.
     */
    public static function for(Organization $organization, AppInstance $app): array
    {
        if ($app->plan?->hidden) {
            return [
                [
                    'url' => '/admin/service/shared-apps',
                    'label' => __('admin.shared_apps.shared_apps'),
                ],
                [
                    'url' => '/admin/service/shared-apps/'.$app->id,
                    'label' => $app->label,
                ],
                [
                    'label' => $app->application->name,
                ],
            ];
        }

        return [
            [
                'label' => __('admin.organizations.organizations'),
                'url' => '/admin/organizations',
            ],
            [
                'label' => $organization->name,
                'url' => '/admin/organizations/'.$organization->id,
            ],
            [
                'label' => __('admin.applications.apps'),
                'url' => '/admin/organizations/'.$organization->id.'/apps',
            ],
            [
                'label' => $app->application->name,
            ],
        ];
    }
}
