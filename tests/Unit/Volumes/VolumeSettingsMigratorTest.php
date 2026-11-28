<?php

use App\Support\Volumes\VolumeSettingsMigrator;

// What the migrator would add to an instance's settings, for the layout of the chart that deploys it
function migratedInstance(string $layout, array $settings, array $plan_volumes = []): array
{
    return (new VolumeSettingsMigrator($layout))->instance($settings, $plan_volumes);
}

function migratedPlan(string $layout, array $settings): array
{
    return (new VolumeSettingsMigrator($layout))->plan($settings);
}

describe('the data volume', function () {
    it('is what Nextcloud kept in its persistence configurations', function () {
        $result = migratedPlan('nextcloud', ['configurations' => [
            'persistence-enabled' => true,
            'persistence-storageClass' => 'longhorn',
            'persistence-accessMode' => 'ReadWriteMany',
            // Declared and never read by anything
            'persistence-existingClaim' => '',
            'persistence-numberOfReplicas' => 2,
        ]]);

        expect($result['volumes'])->toBe(['data' => ['enabled' => true, 'storage_class' => 'longhorn', 'access_mode' => 'ReadWriteMany']])
            ->and($result['notes'])->toBe([]);
    });

    it('is what WordPress and CiviCRM kept, whose access modes are a list or a string', function (string $layout, mixed $modes, ?string $expected) {
        $result = migratedPlan($layout, ['configurations' => ['persistence-enabled' => false, 'persistence-storageClass' => '', 'persistence-accessModes' => $modes]]);

        expect($result['volumes']['data'])->toBe(array_filter(['enabled' => false, 'storage_class' => '', 'access_mode' => $expected], fn ($value) => $value !== null));
    })->with([
        'a list, in WordPress' => ['wordpress', ['ReadWriteOnce'], 'ReadWriteOnce'],
        'a string, in CiviCRM' => ['civicrm', 'ReadWriteMany', 'ReadWriteMany'],
        'a list, in CiviCRM' => ['civicrm', ['ReadWriteMany'], 'ReadWriteMany'],
        'an empty list' => ['wordpress', [], null],
    ]);

    it('keeps the first of several access modes and says so', function () {
        $result = migratedPlan('wordpress', ['configurations' => ['persistence-accessModes' => ['ReadWriteOnce', 'ReadOnlyMany']]]);

        expect($result['volumes']['data']['access_mode'])->toBe('ReadWriteOnce')
            ->and($result['notes'])->toBe(['more than one access mode (ReadWriteOnce, ReadOnlyMany): only the first is kept']);
    });

    it('is only the class for a catalog chart, which was only ever given that', function () {
        $result = migratedPlan('catalog', ['configurations' => ['persistence-storageClass' => 'fast', 'persistence-enabled' => true, 'persistence-accessMode' => 'ReadWriteMany']]);

        expect($result['volumes'])->toBe(['data' => ['storage_class' => 'fast']]);
    });

    it('leaves out what is not true or false for `enabled`, and says so', function () {
        $result = migratedPlan('nextcloud', ['configurations' => ['persistence-enabled' => 'yes']]);

        expect($result['volumes'])->toBe([])
            ->and($result['notes'])->toBe(['configurations.persistence-enabled is not true or false, so it is not kept']);
    });

    it('is nothing for settings that have none of it', function (array $settings) {
        expect(migratedPlan('nextcloud', $settings))->toBe(['volumes' => [], 'notes' => []]);
    })->with([
        'no settings' => [[]],
        'other configurations' => [['configurations' => ['replicaCount' => 2]]],
        'a class that is not text' => [['configurations' => ['persistence-storageClass' => ['a']]]],
    ]);
});

describe('the volumes of the workloads next to the app', function () {
    it('is Nextcloud\'s Redis and MariaDB, out of the configurations the chart takes as they are', function () {
        $result = migratedPlan('nextcloud', ['configurations' => [
            'redis' => [
                'enabled' => true,
                'master' => ['persistence' => ['enabled' => true, 'size' => '3Gi', 'storageClass' => 'fast']],
                'replica' => ['replicaCount' => 1, 'persistence' => ['enabled' => false, 'storageClass' => '']],
            ],
            'mariadb' => ['primary' => ['persistence' => ['enabled' => true, 'size' => '8Gi', 'storageClass' => '', 'accessMode' => 'ReadWriteOnce', 'existingClaim' => '']]],
        ]]);

        expect($result['volumes'])->toBe([
            'redis-master' => ['enabled' => true, 'size' => 3, 'storage_class' => 'fast'],
            'redis-replica' => ['enabled' => false, 'storage_class' => ''],
            'mariadb' => ['enabled' => true, 'size' => 8, 'storage_class' => '', 'access_mode' => 'ReadWriteOnce'],
        ])->and($result['notes'])->toBe([]);
    });

    it('is WordPress\'s MariaDB, and nothing for CiviCRM, whose chart has none', function () {
        $configurations = ['configurations' => ['mariadb' => ['primary' => ['persistence' => ['enabled' => false, 'size' => '4Gi', 'storageClass' => '']]], 'redis' => ['master' => ['persistence' => ['enabled' => true]]]]];

        expect(migratedPlan('wordpress', $configurations)['volumes'])->toBe(['mariadb' => ['enabled' => false, 'size' => 4, 'storage_class' => '']])
            ->and(migratedPlan('civicrm', $configurations)['volumes'])->toBe([]);
    });

    it('does not keep a claim for any of them, and says so', function () {
        $result = migratedPlan('nextcloud', ['configurations' => ['mariadb' => ['primary' => ['persistence' => ['existingClaim' => 'db-data']]]]]);

        expect($result['volumes'])->toBe([])
            ->and($result['notes'])->toBe(['configurations.mariadb.primary.persistence.existingClaim is not kept: only the data volume takes a claim']);
    });

    it('is whole Gi, rounded up when it is not, and says so', function (mixed $size, ?int $gibibytes, bool $noted) {
        $result = migratedPlan('wordpress', ['configurations' => ['mariadb' => ['primary' => ['persistence' => ['size' => $size]]]]]);

        expect($result['volumes']['mariadb']['size'] ?? null)->toBe($gibibytes)
            ->and($result['notes'] !== [])->toBe($noted);
    })->with([
        'whole Gi' => ['8Gi', 8, false],
        'Mi' => ['500Mi', 1, true],
        'a fraction of Gi' => ['1.5Gi', 2, true],
        'Ti' => ['2Ti', 2048, false],
        'decimal G' => ['10G', 10, true],
        'not a size' => ['big', null, true],
        'a number' => [8, null, true],
        'nothing' => ['0Gi', null, false],
    ]);
});

describe('what only an instance has', function () {
    it('is the claim Nextcloud was given', function () {
        $result = migratedInstance('nextcloud', ['existing_claim' => ' restored-data ', 'chart_storage' => 40]);

        expect($result['volumes'])->toBe(['data' => ['claim' => 'restored-data', 'size' => 40]])
            ->and($result['notes'])->toBe([]);
    });

    it('is the claim WordPress and a catalog chart were given, with a note to check it was chosen', function (string $layout) {
        $result = migratedInstance($layout, ['override' => ['pvc' => ['name' => 'restored-data']]]);

        expect($result['volumes'])->toBe(['data' => ['claim' => 'restored-data']])
            ->and($result['notes'])->toBe(["override.pvc.name is kept as the claim ('restored-data'); check that it is one that was chosen and not the chart's own"]);
    })->with(['wordpress', 'catalog']);

    it('is the claim CiviCRM was told to use, and not a name it was not', function (array $pvc, ?string $claim, bool $noted) {
        $result = migratedInstance('civicrm', ['override' => ['pvc' => $pvc]]);

        expect($result['volumes']['data']['claim'] ?? null)->toBe($claim)
            ->and($result['notes'] !== [])->toBe($noted);
    })->with([
        'told to' => [['name' => 'restored-data', 'override' => true], 'restored-data', false],
        'not told to: a name a job found' => [['name' => 'found'], null, true],
        'told not to' => [['name' => 'old', 'override' => false], null, true],
        'told to, with no name' => [['override' => true], null, false],
    ]);

    it('is no claim for a layout that never had one, and none that is blank', function () {
        expect(migratedInstance('other', ['existing_claim' => 'x', 'override' => ['pvc' => ['name' => 'x']]])['volumes'])->toBe([])
            ->and(migratedInstance('nextcloud', ['existing_claim' => '  '])['volumes'])->toBe([]);
    });

    it('is the size it was given, for every layout, and only a size that can be one', function (mixed $stored, ?int $size) {
        foreach (['nextcloud', 'wordpress', 'civicrm', 'catalog', 'other'] as $layout) {
            expect(migratedInstance($layout, ['chart_storage' => $stored])['volumes']['data']['size'] ?? null)->toBe($size);
        }
    })->with([
        'a number' => [50, 50],
        'a number kept as text' => ['50', 50],
        'nothing' => [0, null],
        'null' => [null, null],
    ]);

    it('is the persistence configurations too, for the instance', function () {
        $result = migratedInstance('nextcloud', ['configurations' => ['persistence-storageClass' => 'fast', 'redis' => ['master' => ['persistence' => ['enabled' => true]]]]]);

        expect($result['volumes'])->toBe(['data' => ['storage_class' => 'fast'], 'redis-master' => ['enabled' => true]]);
    });

    it('is only what the plan does not already give, so the instance goes on following it', function () {
        $result = migratedInstance('nextcloud', ['configurations' => ['persistence-storageClass' => 'fast', 'persistence-accessMode' => 'ReadWriteOnce', 'persistence-enabled' => true]], [
            'data' => ['storage_class' => 'fast', 'access_mode' => 'ReadWriteMany'],
        ]);

        // Equal to the plan's: left out. Different: its own
        expect($result['volumes'])->toEqual(['data' => ['access_mode' => 'ReadWriteOnce', 'enabled' => true]]);
    });

    it('is nothing when everything it keeps is what the plan gives', function () {
        $result = migratedInstance('nextcloud', ['configurations' => ['persistence-storageClass' => 'fast']], ['data' => ['storage_class' => 'fast']]);

        expect($result['volumes'])->toBe([]);
    });
});
