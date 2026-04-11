<?php

namespace App\Services;

use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class MenuService
{
    private array $admin = [];

    private array $organization = [];

    public function admin()
    {
        $admin = Gate::allows('admin');

        $this->admin = [
            'organizations' => [
                'name' => __('labels.organizations'),
                'url' => '/admin/organizations',
                'icon' => 'fa-building-flag',
                'perm' => $admin,
                'external' => false,
                'order' => 100,
            ],
            'apps' => [
                'name' => __('labels.apps'),
                'url' => '/admin/apps',
                'icon' => 'fa-rocket',
                'perm' => $admin,
                'external' => false,
                'order' => 200,
            ],
            'services' => [
                'name' => __('labels.services'),
                'url' => '/admin/service',
                'icon' => 'fa-handshake',
                'perm' => $admin,
                'external' => false,
                'order' => 300,
                'submenu' => [
                    [
                        'name' => __('labels.announcements'),
                        'url' => '/admin/service/announcements',
                        'icon' => 'fa-bullhorn',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 100,
                    ],
                    [
                        'name' => __('labels.plans'),
                        'url' => '/admin/service/plans',
                        'icon' => 'fa-circle',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 200,
                    ],
                    [
                        'name' => __('labels.shared_apps'),
                        'url' => '/admin/service/shared-apps',
                        'icon' => 'fa-circle',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 300,
                    ],
                    [
                        'name' => __('labels.domains'),
                        'url' => '/admin/service/domains',
                        'icon' => 'fa-circle',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 300,
                    ],
                ],
            ],
            'server_settings' => [
                'name' => __('labels.server_settings'),
                'url' => '/admin/server',
                'icon' => 'fa-sliders-h',
                'perm' => $admin,
                'external' => false,
                'order' => 400,
                'submenu' => [
                    [
                        'name' => __('labels.tests'),
                        'url' => '/admin/server/tests',
                        'icon' => 'fa-vial',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 100,
                    ],
                    [
                        'name' => __('labels.tasks'),
                        'url' => '/admin/server/tasks',
                        'icon' => 'fa-tasks',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 200,
                    ],
                    [
                        'name' => __('labels.backups'),
                        'url' => '/admin/server/backup_scheduler',
                        'icon' => 'fa-download',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 400,
                    ],
                    [
                        'name' => __('labels.servers'),
                        'url' => '/admin/server/servers',
                        'icon' => 'fa-download',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 500,
                    ],
                    [
                        'name' => __('labels.logs'),
                        'url' => '/admin/server/logs',
                        'icon' => 'fa-download',
                        'perm' => $admin,
                        'external' => false,
                        'order' => 600,
                    ],
                ],
            ],
            'settings' => [
                'name' => __('labels.control_panel_settings'),
                'url' => '/admin/settings',
                'icon' => 'fa-toolbox',
                'perm' => $admin,
                'external' => false,
                'order' => 500,
            ],
        ];
    }

    public function organization()
    {
        $organization = Organization::account();
        $organization_status = $organization->status;
        $billable = Gate::allows('has-billing-account');

        return [
            'dashboard' => [
                'name' => __('labels.dashboard'),
                'url' => '/',
                'icon' => 'vuestic-iconset-dashboard',
                'perm' => $organization_status != 'new',
                'external' => false,
                'order' => 100,
            ],
            'welcome' => [
                'name' => __('labels.welcome'),
                'url' => '/',
                'icon' => 'fa-star-of-life',
                'perm' => $organization_status == 'new',
                'external' => false,
                'order' => 100,
            ],
            'apps' => [
                'name' => __('labels.apps'),
                'url' => '/apps',
                'icon' => 'vuestic-iconset-components',
                'perm' => $organization_status != 'new',
                'external' => false,
                'order' => 200,
            ],
            'plans' => [
                'name' => __('labels.plans'),
                'url' => '/subscription/options',
                'icon' => 'fa-flag-checkered',
                'perm' => $organization_status == 'new',
                'external' => false,
                'order' => 300,
            ],
            'organizations' => [
                'name' => __('labels.organization'),
                'url' => '/settings/organization',
                'icon' => 'fa-building',
                'perm' => $organization_status == 'new',
                'external' => false,
                'order' => 400,
            ],
            'users' => [
                'name' => __('labels.users'),
                'url' => '/users',
                'icon' => 'vuestic-iconset-user',
                'perm' => $organization_status != 'new',
                'external' => false,
                'order' => 500,
            ],
            'groups' => [
                'name' => __('labels.groups'),
                'url' => '/groups',
                'icon' => 'entypo-users',
                'perm' => $organization_status != 'new',
                'external' => false,
                'order' => 600,
            ],
            'settings' => [
                'name' => __('labels.settings'),
                'url' => '/settings',
                'icon' => 'vuestic-iconset-statistics',
                'perm' => $organization_status != 'new',
                'external' => false,
                'order' => 700,
                'submenu' => [
                    [
                        'name' => __('labels.organization'),
                        'url' => '/settings/organization',
                        'icon' => 'fa-building',
                        'perm' => true,
                        'external' => false,
                        'order' => 100,
                    ],
                    [
                        'name' => __('labels.suborganizations'),
                        'url' => '/settings/suborganizations',
                        'icon' => 'fa-building',
                        'perm' => Gate::allows('view-suborganizations'),
                        'external' => false,
                        'order' => 200,
                    ],
                    [
                        'name' => __('labels.domains'),
                        'url' => '/settings/domains',
                        'icon' => 'fa-bolt',
                        'perm' => Gate::allows('view-domains'),
                        'external' => false,
                        'order' => 300,
                    ],
                    [
                        'name' => __('labels.email_accounts'),
                        'url' => '/settings/email/accounts',
                        'icon' => 'fa-envelope',
                        'perm' => Gate::allows('view-emails'),
                        'external' => false,
                        'order' => 400,
                    ],
                ],
            ],
            'subscriptions' => [
                'name' => __('labels.subscription'),
                'url' => '/subscription',
                'icon' => 'vuestic-iconset-forms',
                'perm' => $organization_status != 'new',
                'order' => 800,
                'submenu' => [
                    [
                        'name' => __('labels.summary'),
                        'url' => '/subscription',
                        'icon' => 'fa-file-contract',
                        'perm' => $billable,
                        'external' => false,
                        'order' => 100,
                    ],
                    [
                        'name' => __('labels.plans'),
                        'url' => '/subscription/plans',
                        'perm' => true,
                        'external' => false,
                        'order' => 200,
                    ],
                    [
                        'name' => __('labels.billing_info'),
                        'url' => '/subscription/payment',
                        'icon' => 'fa-credit-card',
                        'perm' => $billable,
                        'external' => false,
                        'order' => 300,
                    ],
                ],
            ],
            'documentation' => [
                'name' => __('labels.documentation'),
                'url' => Settings::get('docs_url'),
                'icon' => 'fa-file-alt',
                'perm' => Settings::get('docs_url') && Settings::get('docs_url') !== '',
                'external' => true,
                'order' => 10000,
            ],
        ];
    }

    public function registerItem(string $menu, array|\Closure $item, ?string $menu_item = null)
    {
        if (! in_array($menu, ['admin', 'organization'])) {
            return;
        }

        if ($menu_item) {
            if (! Arr::has($this->$menu, "$menu_item.submenu")) {
                $this->$menu[$menu_item]['submenu'] = [];
            }
            $this->$menu[$menu_item]['submenu'][] = $item;
        } else {
            $this->$menu[] = $item;
        }
    }

    public function build(string $menu)
    {
        $items = $this->$menu();
        foreach ($this->$menu as $name => $item) {
            if (is_string($name) && Arr::has($items, "$name.submenu")) {
                foreach ($item['submenu'] as $submenu_item) {
                    if ($submenu_item instanceof \Closure) {
                        $items[$name]['submenu'][] = $submenu_item();
                    } else {
                        $items[$name]['submenu'][] = $submenu_item;
                    }
                }
            } else {
                if ($item instanceof \Closure) {
                    $items[] = $item();
                } else {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }
}
