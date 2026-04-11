<?php

namespace App\Integrations\Applications\Nextcloud\API;

use App\Integrations\Applications\Nextcloud\Nextcloud;
use App\Services\AdditionalStorageService;
use App\Support\ByteConversion;
use Illuminate\Support\Str;

class GroupFolders extends Nextcloud
{
    public $data;

    private function url()
    {
        return $this->app_instance->address();
    }

    public function find($id)
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.(string) $id;

        $this->action_description = __('messages.api.nextcloud.team_folders.get', ['name' => (string) $id]);
        $this->form()->get($path);

        $this->data = $this->response_content();

        return $this->response_content();
    }

    public function findByName($name)
    {
        $name = Str::of($name)
            ->trim();
        $this->data = null;

        $group_folders = $this->all();
        if ($group_folders && count((array) $group_folders) > 0) {
            foreach ($group_folders as $group_folder) {
                $mountpoint = Str::of($group_folder->mount_point)->trim();

                if ($mountpoint == $name) {
                    $this->data = $group_folder;
                }
            }
        }

        return $this->data;
    }

    public function all()
    {
        $path = $this->baseURI().'/apps/groupfolders/folders';

        $this->action_description = __('messages.api.nextcloud.team_folders.all');
        $this->form()->get($path);

        return $this->response_content()->element;
    }

    public function exists()
    {
        return $this->data ? true : false;
    }

    public function add($mountpoint, AdditionalStorageService $additional_storage)
    {
        $mountpoint = trim($mountpoint);

        // Create Team Folder
        $this->request_type = 'POST';
        $path = $this->baseURI().'/apps/groupfolders/folders';
        $data = ['mountpoint' => $mountpoint];

        $this->action_description = __('messages.api.nextcloud.team_folders.add');
        $this->form()->post($path, $data);

        $this->data = $this->response_content();

        if ($this->data) {

            // Enable ACL
            $this->enableAcl();

            $this->updateQuota($additional_storage);

        }

        return $this->data;
    }

    public function enableAcl()
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/acl';
        $data = ['acl' => 1];
        $this->action_description = __('messages.api.nextcloud.team_folders.enable_acl');

        return $this->form()->post($path, $data);
    }

    public function updateQuota(AdditionalStorageService $additional_storage)
    {
        // Quota should only be based on additional storage. There is no base amount
        $gigabytes = new ByteConversion;
        $quota = $gigabytes($additional_storage->quota(), 'gb', 'b', 'byte');

        // Set folder quota
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/quota';
        $data = ['quota' => $quota];

        $this->action_description = __('messages.api.nextcloud.team_folders.update_quota');

        return $this->form()->post($path, $data);

    }

    public function updateAllQuotas()
    {

        $all_folders = $this->all();

        foreach ($all_folders as $folder) {
            $this->data = $folder;
            $additional_storage = new AdditionalStorageService(
                organization: $this->organization,
                entity: 'group',
                name: (string) $folder->mount_point,
                app_instance: $this->app_instance);

            $this->updateQuota($additional_storage);
        }

    }

    public function updateMountPoint($mountpoint)
    {
        // Update Team Folder mount point
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/mountpoint';
        $data = ['mountpoint' => $mountpoint];
        $this->action_description = __('messages.api.nextcloud.team_folders.update_name');

        return $this->form()->post($path, $data);

    }

    public function addGroup($group)
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/groups';
        $data = ['group' => $group];
        $this->action_description = __('messages.api.nextcloud.team_folders.add_group');

        return $this->form()->post($path, $data);
    }

    public function removeGroup($name)
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/groups/'.$name;

        return $this->delete($path);
    }

    public function addManager($manager)
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/manageACL';
        $data = [
            'mappingId' => $manager,
            'mappingType' => 'user',
            'manageAcl' => true,
        ];

        $this->action_description = __('messages.api.nextcloud.team_folders.add_manager');

        return $this->form()->post($path, $data);
    }

    public function removeManager($manager)
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id.'/manageACL';
        $data = [
            'mappingId' => $manager,
            'mappingType' => 'user',
            'manageAcl' => false,
        ];
        $this->action_description = __('messages.api.team_folders.remove_manager');
        $this->form()->post($path, $data);

        return $this;

    }

    public function remove()
    {
        $path = $this->baseURI().'/apps/groupfolders/folders/'.$this->data->id;
        $this->action_description = __('messages.api.team_folders.delete');

        return $this->delete($path);
    }
}
