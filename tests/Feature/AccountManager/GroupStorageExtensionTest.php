<?php

use App\AdditionalStorage;
use App\Application;
use App\User;
use Tests\Support\TestSupports;

describe('Group Storage Extension (feature)', function () {
    beforeEach(function () {
        setupAccountManagerDriver('db');
        $this->support = new TestSupports;
        $this->support->seed();
        $this->support->activateDemoAppWithStorage(storageAmount: 10, storageMax: 5);
        $this->support->addUsers();

        $this->user = User::where('username', 'demo')->firstOrFail();
        $this->actingAs($this->user);

        $demo_app = Application::where('slug', 'demo_app')->first();
        $this->appInstance = $demo_app->instances()->first();
    });

    it('adds additional storage for a group when the extension is enabled', function () {
        $name = 'storage-group';

        $this->post('/groups', ['name' => $name, 'category' => 'teams'])
            ->assertRedirectContains($name);

        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => [
                'demo_group_storage' => true,
                'demo_additional_storage' => 2,
            ],
        ])->assertValid();

        $storage = AdditionalStorage::where('name', $name)
            ->where('entity', 'group')
            ->where('app_instance_id', $this->appInstance->id)
            ->first();

        expect($storage)->not->toBeNull();
        expect($storage->quantity)->toBe(2);
    });

    it('updates the storage quantity when the amount is changed', function () {
        $name = 'update-storage-group';

        $this->post('/groups', ['name' => $name, 'category' => 'teams']);

        // Add storage with quantity 2
        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => [
                'demo_group_storage' => true,
                'demo_additional_storage' => 2,
            ],
        ]);

        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->value('quantity'))->toBe(2);

        // Update storage to quantity 4
        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => [
                'demo_group_storage' => true,
                'demo_additional_storage' => 4,
            ],
        ]);

        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->value('quantity'))->toBe(4);
    });

    it('removes additional storage when the extension is disabled', function () {
        $name = 'remove-storage-group';

        $this->post('/groups', ['name' => $name, 'category' => 'teams']);

        // Add storage
        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => [
                'demo_group_storage' => true,
                'demo_additional_storage' => 3,
            ],
        ]);

        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->exists())->toBeTrue();

        // Disable storage
        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => [
                'demo_group_storage' => false,
            ],
        ]);

        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->exists())->toBeFalse();
    });

    it('does not create storage when the extension flag is absent', function () {
        $name = 'no-storage-group';

        $this->post('/groups', ['name' => $name, 'category' => 'teams']);

        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
        ]);

        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->exists())->toBeFalse();
    });

    it('does not exceed the plan storage maximum', function () {
        // Tighten storage max to 2 units
        $plan = $this->appInstance->plan;
        $settings = $plan->settings;
        $settings['storage']['max'] = 2;
        $plan->settings = $settings;
        $plan->save();

        $firstName = 'max-group-one';
        $secondName = 'max-group-two';
        $thirdName = 'max-group-three';

        $this->post('/groups', ['name' => $firstName, 'category' => 'teams']);
        $this->post('/groups', ['name' => $secondName, 'category' => 'teams']);
        $this->post('/groups', ['name' => $thirdName, 'category' => 'teams']);

        // Use both storage slots
        $this->put("/groups/{$firstName}", [
            'original_name' => $firstName,
            'name' => $firstName,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 1],
        ]);

        $this->put("/groups/{$secondName}", [
            'original_name' => $secondName,
            'name' => $secondName,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 1],
        ]);

        // Third group — storage max already reached, trying to add 1 should be clamped to 0
        $this->put("/groups/{$thirdName}", [
            'original_name' => $thirdName,
            'name' => $thirdName,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 1],
        ]);

        // Third group should have no storage record (max exceeded)
        expect(AdditionalStorage::where('name', $thirdName)->where('entity', 'group')->exists())->toBeFalse();
    });

    it('extension reports correct checkbox and storage values after saving', function () {
        $name = 'extension-values-group';

        $this->post('/groups', ['name' => $name, 'category' => 'teams']);

        // Before adding storage there should be no record in the DB
        expect(AdditionalStorage::where('name', $name)->where('entity', 'group')->exists())->toBeFalse();

        // Add storage quantity 3
        $this->put("/groups/{$name}", [
            'original_name' => $name,
            'name' => $name,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 3],
        ]);

        // Verify via DB that quantity is 3 and the extension will return value = true
        $storage = AdditionalStorage::where('name', $name)
            ->where('entity', 'group')
            ->where('app_instance_id', $this->appInstance->id)
            ->first();

        expect($storage)->not->toBeNull();
        expect($storage->quantity)->toBe(3);

        // Call the extension directly to verify it returns the correct values
        $extensions = collect($this->appInstance->extension('groups', ['name' => $name, 'action' => 'update']));

        $checkbox = $extensions->firstWhere('id', 'demo_group_storage');
        $select = $extensions->firstWhere('id', 'demo_additional_storage');

        expect($checkbox)->not->toBeNull();
        expect($checkbox['value'])->toBeTrue();
        expect($select)->not->toBeNull();
        expect($select['value'])->toBe(3);
    });

    it('preserves storage when renaming a group', function () {
        $originalName = 'rename-source-group';
        $newName = 'rename-target-group';

        $this->post('/groups', ['name' => $originalName, 'category' => 'teams']);

        $this->put("/groups/{$originalName}", [
            'original_name' => $originalName,
            'name' => $originalName,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 2],
        ]);

        expect(AdditionalStorage::where('name', $originalName)->where('entity', 'group')->value('quantity'))->toBe(2);

        // Rename the group while keeping storage enabled
        $this->put("/groups/{$originalName}", [
            'original_name' => $originalName,
            'name' => $newName,
            'category' => 'teams',
            'managers' => [],
            'members' => [],
            'extensions' => ['demo_group_storage' => true, 'demo_additional_storage' => 2],
        ]);

        // Storage record under original name should be preserved (original_name is used for lookup)
        expect(AdditionalStorage::where('name', $originalName)->where('entity', 'group')->value('quantity'))->toBe(2);
    });
});
