<?php

namespace Tests\Feature\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Services\AdditionalStorageService;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class GroupFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_nextcloud_group_folders_api()
    {
        $support = new TestSupports;
        $support->seed();
        $support->populate();
        $user = User::find(1);
        $nextcloud = AppInstance::where('name', 'nextcloud')->first();

        $support->setSubscription($user->organization, $support->base_1, $support->nextcloud_1, $nextcloud);
        $nextcloud->fresh();

        $additional_storage = new AdditionalStorageService($nextcloud->organization, 'group', 'Demo', $nextcloud);
        $additional_storage->add($support->nextcloud_1->setting('storage.amount'));
        // Run team folder check
        $group_folder = new GroupFolders($nextcloud);

        // If this tests breaks midway, it can leave group folders behind. This cleans them up first the group folders first
        $demo = $group_folder->findByName('Demo');
        if ($group_folder->exists()) {
            $group_folder->remove();
        }
        $demo1 = $group_folder->findByName('Demo1');
        if ($group_folder->exists()) {
            $group_folder->remove();
        }
        // Done cleanup

        $demo = $group_folder->findByName('Demo');
        $this->assertFalse($group_folder->exists());
        $add = $group_folder->add('Demo', $additional_storage);
        $this->assertFalse($group_folder->hasError());
        $update_quota = $group_folder->updateQuota($additional_storage);
        $this->assertFalse($group_folder->hasError());
        $update_mountpoint = $group_folder->updateMountPoint('Demo1');
        $this->assertFalse($group_folder->hasError());
        $demo1 = $group_folder->findByName('Demo1');
        $this->assertFalse($group_folder->hasError());
        $this->assertEquals('Demo1', $demo1->mount_point);

        // Add groups
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(0, count($folder->groups->element));
        $add_group = $group_folder->addGroup('admin');
        $this->assertFalse($group_folder->hasError());
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(1, count($folder->groups->element));

        $remove_group = $group_folder->removeGroup('admin');
        $this->assertFalse($group_folder->hasError());
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(0, count($folder->groups->element));

        // Managers
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(0, count($folder->manage->element));
        $add_manager = $group_folder->addManager('demo');

        // Confirm manager
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(1, count($folder->manage->element));
        $this->assertFalse($group_folder->hasError());
        $remove_manager = $group_folder->removeManager('demo');
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(0, count($folder->manage->element));

        // Check quota size
        $this->assertFalse($group_folder->hasError());
        $folder = $group_folder->findByName('Demo1');
        $this->assertEquals(26843545600, (int) $folder->quota);
        $this->assertEquals(0, (int) $folder->size);

        // Check removed
        $group_folder->remove();
        sleep(1);
        $group_folder->findByName('Demo1');
        $this->assertFalse($group_folder->hasError());
        $this->assertFalse($group_folder->exists());
    }
}
