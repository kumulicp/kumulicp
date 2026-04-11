<?php

namespace App\Integrations\Applications\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Integrations\Applications\Nextcloud\API\Users;
use App\Services\AdditionalStorageService;
use App\Support\Facades\Application;
use App\Support\Facades\Subscription;
use Illuminate\Support\Facades\Gate;
use Throwable;

class NextcloudExtensions
{
    public function __construct(private AppInstance $app_instance) {}

    public function groups($attributes)
    {

        $mounted = false;
        $hide = '';
        $additional_storage = '';

        if (array_key_exists('action', $attributes) && $attributes['action'] == 'update' && array_key_exists('name', $attributes)) {

            try {
                $group_folder = new GroupFolders($this->app_instance);
                $group_folder->findByName($attributes['name']);

                if ($group_folder->exists()) {
                    $mounted = true;
                }
            } catch (Throwable $e) {
                report($e);

                // Report error
                return [
                    [
                        'label' => __('messages.extensions.nextcloud.add_team_folder'),
                        'input' => __('messages.extensions.nextcloud.connection_error'),
                        'id' => 'nextcloud_group_folder',
                    ],
                ];
            }
        }

        $plan = $this->app_instance->plan;
        if ($plan->setting('storage.amount') && $plan->setting('storage.amount') > 0) {
            $plan = Subscription::app_instance($this->app_instance);

            if (! $mounted && $plan && $plan->isMax('storage')) {
                return [
                    [
                        'label' => __('messages.extensions.nextcloud.add_team_folder'),
                        'input' => __('messages.extensions.nextcloud.max_reached'),
                        'id' => 'nextcloud_group_folder',
                    ],
                ];
            }
            $name = array_key_exists('name', $attributes) ? $attributes['name'] : 'new';

            $additional_storage = new AdditionalStorageService($this->app_instance->organization, 'group', $name, $this->app_instance);
            $additional_storage_options = $additional_storage->additionalStorageOptions();
            $quantity = $additional_storage->quantity();

            return [
                [
                    'label' => __('messages.extensions.nextcloud.add_team_folder'),
                    'input' => 'va-checkbox',
                    'id' => 'nextcloud_group_folder',
                    'value' => $mounted,
                    'warning' => __('messages.extensions.nextcloud.subscription_affected'),
                ],
                [
                    'label' => __('messages.extensions.nextcloud.team_folder_storage'),
                    'input' => 'va-select',
                    'id' => 'nextcloud_additional_storage',
                    'value' => $quantity > 0 ? $quantity : 1,
                    'options' => $additional_storage_options,
                    'conditional' => 'nextcloud_group_folder',
                    'warning' => __('messages.extensions.nextcloud.subscription_affected'),
                ],
            ];
        }

        return [];
    }

    public function permissions($username)
    {
        $app = Application::instance($this->app_instance);
        if (Gate::allows('admin') && $app->configuration('admin-access')) {
            try {
                $user = new Users($this->app_instance);
                $user->find($username);

                $granted = $user->checkPermission('admin');

            } catch (Throwable $e) {
                report($e);

                return [];
            }

            return [
                [
                    'label' => __('labels.admin'),
                    'allow' => $granted,
                    'id' => 'nextcloud_admin',
                    'type' => 'login',
                ],
            ];
        }

        return [];
    }
}
