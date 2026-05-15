<?php

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\GroupFolders;
use App\Services\AdditionalStorageService;
use App\User;
use Tests\Support\TestSupports;

it('manages nextcloud group folders via API', function () {
    $support = new TestSupports;
    $support->seed();
    $support->createNextcloudPlans();
    $user = User::where('username', 'demo')->firstOrFail();
    $nextcloud = AppInstance::where('name', 'nextcloud')->first();

    $support->setSubscription($user->organization, $support->base_1, $support->nextcloud_1, $nextcloud);
    $nextcloud->fresh();

    $additional_storage = new AdditionalStorageService($nextcloud->organization, 'group', 'Demo', $nextcloud);
    $additional_storage->add($support->nextcloud_1->setting('storage.amount'));

    $group_folder = new GroupFolders($nextcloud);

    // Cleanup any leftover folders from previous interrupted runs
    $demo = $group_folder->findByName('Demo');
    if ($group_folder->exists()) {
        $group_folder->remove();
    }
    $demo1 = $group_folder->findByName('Demo1');
    if ($group_folder->exists()) {
        $group_folder->remove();
    }

    $demo = $group_folder->findByName('Demo');
    expect($group_folder->exists())->toBeFalse();
    $group_folder->add('Demo', $additional_storage);
    expect($group_folder->hasError())->toBeFalse();
    $group_folder->updateQuota($additional_storage);
    expect($group_folder->hasError())->toBeFalse();
    $group_folder->updateMountPoint('Demo1');
    expect($group_folder->hasError())->toBeFalse();
    $demo1 = $group_folder->findByName('Demo1');
    expect($group_folder->hasError())->toBeFalse();
    expect($demo1->mount_point)->toBe('Demo1');

    // Add groups
    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->groups->element))->toBe(0);
    $group_folder->addGroup('admin');
    expect($group_folder->hasError())->toBeFalse();
    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->groups->element))->toBe(1);

    $group_folder->removeGroup('admin');
    expect($group_folder->hasError())->toBeFalse();
    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->groups->element))->toBe(0);

    // Managers
    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->manage->element))->toBe(0);
    $group_folder->addManager('demo');

    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->manage->element))->toBe(1);
    expect($group_folder->hasError())->toBeFalse();
    $group_folder->removeManager('demo');
    $folder = $group_folder->findByName('Demo1');
    expect(count($folder->manage->element))->toBe(0);

    // Quota size
    expect($group_folder->hasError())->toBeFalse();
    $folder = $group_folder->findByName('Demo1');
    expect((int) $folder->quota)->toBe(26843545600);
    expect((int) $folder->size)->toBe(0);

    // Removal
    $group_folder->remove();
    sleep(1);
    $group_folder->findByName('Demo1');
    expect($group_folder->hasError())->toBeFalse();
    expect($group_folder->exists())->toBeFalse();
});
