<?php

use App\Integrations\ServerManagers\Kubernetes\Charts\HelmChart;
use App\Support\Volumes\VolumeSpec;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * Charts declare their volumes and HelmChart writes them (docs/volumes-and-settings-plan.md, Slice 2):
 * what a volume kept in the one place is given to each kind of chart, and what the declaration says
 * about size. What each chart gave before is pinned in ChartVolumeValuesTest.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
});

// What an instance keeps for its volume, in the place every chart reads it from
function volumeKept(array $fields, string $name = 'data'): array
{
    return ['workloads' => ['app' => ['volumes' => [$name => $fields]]]];
}

describe('the volume an instance keeps', function () {
    it('is what the chart is given for its claim, over where the chart used to keep it', function (string $slug, string $old_place, array $old_value, string $key) {
        $app_instance = Fixtures::instance($slug, settings: array_replace_recursive($old_value, volumeKept(['claim' => 'restored-data'])));

        expect(Fixtures::values($app_instance)['persistence'][$key])->toBe('restored-data');
    })->with([
        'Nextcloud' => ['nextcloud', 'existing_claim', ['existing_claim' => 'old-claim'], 'existingClaim'],
        'WordPress' => ['wordpress', 'override.pvc.name', ['override' => ['pvc' => ['name' => 'old-claim']]], 'existingClaim'],
        'CiviCRM' => ['civicrm-standalone', 'override.pvc.name', ['override' => ['pvc' => ['name' => 'old-claim', 'override' => true]]], 'existingClaim'],
    ]);

    it('is what a catalog chart is given for its claim, over where the chart used to keep it', function () {
        $app_instance = Fixtures::catalogInstance(
            ['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']],
            [],
            ['override' => ['pvc' => ['name' => 'old-claim']]] + volumeKept(['claim' => 'restored-data']),
        );

        expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->toBe('restored-data');
    });

    it('is a decision that there is no claim when it is none, whatever the chart kept before', function () {
        // What a move to a new volume writes
        $app_instance = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'old-claim']]] + volumeKept(['claim' => null]));

        expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->toBe('');
    });

    it('is given its class and mode to the chart, a list where the chart takes one', function () {
        $settings = volumeKept(['storage_class' => 'fast', 'access_mode' => 'ReadWriteMany']);

        $nextcloud = Fixtures::values(Fixtures::instance('nextcloud', settings: $settings))['persistence'];
        $wordpress = Fixtures::values(Fixtures::instance('wordpress', settings: $settings))['persistence'];

        expect($nextcloud)->toMatchArray(['storageClass' => 'fast', 'accessMode' => 'ReadWriteMany'])
            ->and($wordpress)->toMatchArray(['storageClass' => 'fast', 'accessModes' => ['ReadWriteMany']]);
    });

    it('is turned on and off by the instance', function () {
        $on = Fixtures::values(Fixtures::instance('nextcloud', ['persistence-enabled' => false], volumeKept(['enabled' => true])));

        expect($on['persistence']['enabled'])->toBeTrue();
    });

    it('is written to the generic chart\'s volume of that name, in its own lists', function () {
        $extra = fn (string $field, string $type = 'string') => ['name' => "persistence-1-$field", 'type' => $type, 'persistent' => true, 'additional' => true];

        $app_instance = Fixtures::instance('generic', [
            'persistence-enabled' => true,
            'persistence-1-name' => 'uploads',
            'persistence-1-mountPath' => '/uploads',
        ], array_replace_recursive(
            volumeKept(['storage_class' => 'fast', 'access_mode' => 'ReadWriteMany']),
            volumeKept(['claim' => 'shared-uploads'], 'uploads'),
        ), [
            'persistence-1-name' => $extra('name'),
            'persistence-1-mountPath' => $extra('mountPath'),
        ]);

        $persistence = Fixtures::values($app_instance)['persistence'];

        expect($persistence['volumes'][1]['persistentVolumeClaim']['claimName'])->toBe('shared-uploads')
            ->and(array_column($persistence['persistentVolumeClaims'], 'name'))->toBe(['data'])
            ->and($persistence['persistentVolumeClaims'][0])->toMatchArray(['storageClassName' => 'fast', 'accessModes' => ['ReadWriteMany']]);
    });
});

describe('the size', function () {
    it('follows the quota, grows with it and never shrinks, kept in the volume\'s own place', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['storage_quota' => 10]);

        expect(Fixtures::values($app_instance)['persistence']['size'])->toBe('10Gi');

        // The subscription gives less now; the claim can't be made smaller
        $app_instance->updateSetting('storage_quota', 4);

        expect(Fixtures::values($app_instance)['persistence']['size'])->toBe('10Gi')
            ->and($app_instance->fresh()->setting('workloads.app.volumes.data.size'))->toBe(10);

        $app_instance->updateSetting('storage_quota', 25);

        expect(Fixtures::values($app_instance)['persistence']['size'])->toBe('25Gi');
    });

    it('is the storage of the release the chart is given, without keeping it', function () {
        $app_instance = Fixtures::instance('wordpress');
        $chart = Fixtures::chart($app_instance);
        $chart->app_storage = 7;

        expect($chart->valuesWithAdditionalConfigs()['persistence']['size'])->toBe('7Gi')
            ->and($app_instance->fresh()->setting('workloads'))->toBeNull();
    });

    it('is the generic volume\'s own when it has one, and the quota\'s when it has none', function () {
        $extra = fn (string $field, string $type = 'string') => ['name' => "persistence-1-$field", 'type' => $type, 'persistent' => true, 'additional' => true];

        $app_instance = Fixtures::instance('generic', [
            'persistence-enabled' => true,
            'persistence-0-size' => 3,
            'persistence-1-name' => 'uploads',
            'persistence-1-mountPath' => '/uploads',
        ], ['storage_quota' => 10], [
            'persistence-0-size' => $extra('size', 'int'),
            'persistence-1-name' => $extra('name'),
            'persistence-1-mountPath' => $extra('mountPath'),
        ]);

        $sizes = fn () => array_column(Fixtures::values($app_instance)['persistence']['persistentVolumeClaims'], 'storage', 'name');

        expect($sizes())->toBe(['data' => '3Gi', 'uploads' => '10Gi']);

        // The one with a size of its own stays; the other follows the quota
        $app_instance->updateSetting('storage_quota', 20);

        expect($sizes())->toBe(['data' => '3Gi', 'uploads' => '20Gi']);
    });
});

describe('what a chart declares', function () {
    it('is nothing for a chart that writes its own persistence values', function () {
        $chart = Fixtures::chart(Fixtures::instance('nextcloud'));

        $undeclared = new class($chart->organization, $chart->app_instance) extends HelmChart
        {
            public function values(): array
            {
                return [];
            }
        };

        expect($undeclared->volumes())->toBe([])
            ->and($undeclared->volumeValues())->toBe([]);
    });

    it('is the one volume of each chart that holds the files, sized by the quota', function (string $slug) {
        $specs = Fixtures::chart(Fixtures::instance($slug))->volumes();

        expect($specs)->toHaveCount(1)
            ->and($specs[0])->toBeInstanceOf(VolumeSpec::class)
            ->and($specs[0]->id())->toBe('app/data')
            ->and($specs[0])->primary->toBeTrue()->size_policy->toBe('quota');
    })->with(['nextcloud', 'wordpress', 'civicrm-standalone']);

    it('is a volume for the catalog chart when it was detected to have one, at the keys it was detected at', function () {
        $chart = Fixtures::chart(Fixtures::catalogInstance(['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']]));

        expect($chart->volumes())->toHaveCount(1)
            ->and($chart->volumes()[0]->map)->toBe(['size' => 'persistence.size', 'storage_class' => 'persistence.storageClass', 'claim' => 'persistence.existingClaim', 'only_set' => true]);
    });

    it('is no volume for a catalog chart that was not detected to have one', function () {
        $chart = Fixtures::chart(Fixtures::catalogInstance(['image' => ['tag' => '1']]));

        expect($chart->volumes())->toBe([])
            ->and($chart->volumeValues())->toBe([]);
    });

    it('leaves a catalog chart the keys it has no value for, and the setting it has no key for', function () {
        $app_instance = Fixtures::catalogInstance(
            ['persistence' => ['enabled' => true, 'size' => '10Gi']],
            [],
            volumeKept(['storage_class' => 'fast', 'claim' => 'restored-data', 'access_mode' => 'ReadWriteMany']),
        );

        // The chart has no storageClass or existingClaim key to write to, and takes no access mode
        expect(Fixtures::values($app_instance)['persistence'])->toBe(['size' => '2Gi']);
    });

    it('is a volume for each named generic item, the first the primary, and none while persistence is off', function () {
        $extra = fn (string $field, string $type = 'string') => ['name' => "persistence-1-$field", 'type' => $type, 'persistent' => true, 'additional' => true];

        $on = Fixtures::chart(Fixtures::instance('generic', [
            'persistence-enabled' => true,
            'persistence-1-name' => 'uploads',
            'persistence-1-mountPath' => '/uploads',
            'persistence-1-size' => 5,
        ], [], ['persistence-1-name' => $extra('name'), 'persistence-1-mountPath' => $extra('mountPath'), 'persistence-1-size' => $extra('size', 'int')]));
        $off = Fixtures::chart(Fixtures::instance('generic'));

        expect(array_map(fn (VolumeSpec $spec) => [$spec->id(), $spec->primary, $spec->size_policy], $on->volumes()))
            ->toBe([['app/data', true, 'quota'], ['app/uploads', false, 'fixed']])
            ->and($off->volumes())->toBe([]);
    });
});
