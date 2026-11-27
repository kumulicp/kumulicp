<?php

use App\AppInstance;
use App\Support\Volumes\Volumes;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * The volumes of the workloads next to the app (Nextcloud's Redis and MariaDB, WordPress's MariaDB)
 * (docs/volumes-and-settings-plan.md, Slice 2c). Their settings are in the `redis` and `mariadb`
 * configurations, which the chart takes as they are; the store reads them from there until the instance
 * keeps them for the volume, and the chart is only given what differs. ChartVolumeValuesTest pins that a
 * configuration reaches the chart as it is.
 */
beforeEach(function () {
    setupAccountManagerDriver('db');
});

// An instance whose configuration is the given one, and not the profile's merged with it
function withConfiguration(AppInstance $app_instance, string $name, array $configuration): AppInstance
{
    $app_instance->updateSetting("configurations.{$name}", $configuration);

    return $app_instance;
}

// What the instance keeps for a volume in its own place
function keptFor(string $workload, array $fields): array
{
    return ['workloads' => [$workload => ['volumes' => ['data' => $fields]]]];
}

describe('left as the configuration says', function () {
    it('is not given what the configuration has no setting for', function () {
        // A configuration with no `enabled`, no size and no class: the chart's own defaults are not repeated or guessed
        $app_instance = withConfiguration(Fixtures::instance('nextcloud'), 'redis', ['enabled' => true, 'master' => ['persistence' => ['storageClass' => '']]]);

        expect(Fixtures::values($app_instance)['redis'])->toBe(['enabled' => true, 'master' => ['persistence' => ['storageClass' => '']]]);
    });

    it('keeps a size that is not a whole number of Gi as it is', function (string $size) {
        $app_instance = withConfiguration(Fixtures::instance('nextcloud'), 'redis', ['master' => ['persistence' => ['enabled' => true, 'size' => $size]]]);

        expect(Fixtures::values($app_instance)['redis']['master']['persistence']['size'])->toBe($size);
    })->with(['500Mi', '1.5Gi', '2Ti', '10G']);

    it('keeps what is not a boolean, a string or a size as it is', function () {
        $odd = ['master' => ['persistence' => ['enabled' => 'yes', 'size' => 8, 'storageClass' => ['a'], 'existingClaim' => 3]]];
        $app_instance = withConfiguration(Fixtures::instance('nextcloud'), 'redis', $odd);

        expect(Fixtures::values($app_instance)['redis'])->toBe($odd);
    });

    it('is given a size the configuration has, the same size back', function () {
        $app_instance = withConfiguration(Fixtures::instance('wordpress'), 'mariadb', ['enabled' => true, 'primary' => ['persistence' => ['enabled' => true, 'size' => '8Gi', 'storageClass' => 'fast']]]);

        expect(Fixtures::values($app_instance)['mariadb']['primary']['persistence'])->toBe(['enabled' => true, 'size' => '8Gi', 'storageClass' => 'fast']);
    });

    it('does not follow the app\'s quota', function () {
        $app_instance = withConfiguration(Fixtures::instance('nextcloud', settings: ['storage_quota' => 50]), 'mariadb', ['primary' => ['persistence' => ['enabled' => true, 'size' => '8Gi']]]);

        $values = Fixtures::values($app_instance);

        expect($values['persistence']['size'])->toBe('50Gi')
            ->and($values['mariadb']['primary']['persistence']['size'])->toBe('8Gi');
    });
});

describe('what the instance keeps for the volume', function () {
    it('wins over the configuration, field by field, and leaves the rest of it alone', function () {
        $redis = [
            'enabled' => true,
            'image' => ['registry' => 'docker.io', 'tag' => '8.0.1-debian-12-r1'],
            'master' => ['persistence' => ['enabled' => false, 'size' => '3Gi', 'storageClass' => '']],
            'replica' => ['replicaCount' => 1, 'persistence' => ['enabled' => false, 'storageClass' => '']],
        ];
        $app_instance = withConfiguration(Fixtures::instance('nextcloud', settings: keptFor('redis-master', [
            'enabled' => true, 'size' => 5, 'storage_class' => 'fast', 'claim' => 'redis-data',
        ])), 'redis', $redis);

        $values = Fixtures::values($app_instance)['redis'];

        expect($values['master']['persistence'])->toBe(['enabled' => true, 'size' => '5Gi', 'storageClass' => 'fast', 'existingClaim' => 'redis-data'])
            ->and($values['replica'])->toBe($redis['replica'])
            ->and($values['image'])->toBe($redis['image'])
            ->and($values['enabled'])->toBeTrue();
    });

    it('can turn a volume off that the configuration turned on', function () {
        $app_instance = withConfiguration(Fixtures::instance('nextcloud', settings: keptFor('mariadb', ['enabled' => false])), 'mariadb', ['primary' => ['persistence' => ['enabled' => true]]]);

        expect(Fixtures::values($app_instance)['mariadb']['primary']['persistence']['enabled'])->toBeFalse();
    });

    it('turns on a volume the configuration has nothing about', function () {
        $app_instance = withConfiguration(Fixtures::instance('wordpress', settings: keptFor('mariadb', ['enabled' => true, 'size' => 10])), 'mariadb', ['enabled' => false]);

        expect(Fixtures::values($app_instance)['mariadb'])->toBe(['enabled' => false, 'primary' => ['persistence' => ['enabled' => true, 'size' => '10Gi']]]);
    });

    it('is the plan\'s, for what the configuration has nothing about', function () {
        $app_instance = withConfiguration(
            Fixtures::instance('nextcloud', ['persistence-redis-master-storageClass' => 'from-plan', 'persistence-redis-master-size' => 4]),
            'redis',
            ['master' => ['persistence' => ['enabled' => true]]],
        );

        expect(Fixtures::values($app_instance)['redis']['master']['persistence'])->toEqual(['enabled' => true, 'storageClass' => 'from-plan', 'size' => '4Gi']);
    });

    it('is the configuration\'s over the plan\'s, since that is the instance\'s own', function () {
        // Activation keeps the profile's configuration in the instance, with its class; the plan has never been able to set it
        $app_instance = Fixtures::instance('nextcloud', ['persistence-redis-master-storageClass' => 'from-plan']);

        expect(Fixtures::values($app_instance)['redis']['master']['persistence']['storageClass'])->toBe('');
    });

    it('is written for WordPress\'s MariaDB as for Nextcloud\'s', function () {
        $app_instance = Fixtures::instance('wordpress', settings: keptFor('mariadb', ['size' => 12, 'storage_class' => 'fast']));

        expect(Fixtures::values($app_instance)['mariadb']['primary']['persistence'])->toMatchArray(['size' => '12Gi', 'storageClass' => 'fast']);
    });

    it('is a claim for the master and the database, and none for the replicas', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: array_replace_recursive(
            keptFor('redis-master', ['claim' => 'redis-data']),
            keptFor('mariadb', ['claim' => 'db-data']),
        ));

        $values = Fixtures::values($app_instance);

        expect($values['redis']['master']['persistence']['existingClaim'])->toBe('redis-data')
            ->and($values['mariadb']['primary']['persistence']['existingClaim'])->toBe('db-data')
            ->and($values['redis']['replica']['persistence'])->not->toHaveKey('existingClaim');

        // They make their own claims, so there is none to give them
        Volumes::for($app_instance, Fixtures::chart($app_instance)->volumes())->assign('redis-replica', 'data', ['claim' => 'x']);
    })->throws(LogicException::class, 'can not be given a claim');
});

describe('read from the configuration', function () {
    it('is what the store says about the volume until the instance keeps its own', function () {
        $app_instance = withConfiguration(Fixtures::instance('nextcloud'), 'mariadb', ['primary' => ['persistence' => [
            'enabled' => true, 'existingClaim' => 'db-data', 'storageClass' => 'fast', 'accessMode' => 'ReadWriteOnce', 'size' => '8Gi',
        ]]]);

        $volume = Volumes::for($app_instance, Fixtures::chart($app_instance)->volumes())->get('mariadb', 'data');

        expect($volume)->enabled->toBeTrue()->claim->toBe('db-data')->storage_class->toBe('fast')->access_mode->toBe('ReadWriteOnce')->size->toBe(8)
            ->and($volume->on())->toBeTrue();
    });

    it('says nothing about what comes back different, or is not there', function () {
        $app_instance = withConfiguration(Fixtures::instance('nextcloud'), 'redis', ['master' => ['persistence' => ['enabled' => 'yes', 'size' => '500Mi']]]);

        $volume = Volumes::for($app_instance, Fixtures::chart($app_instance)->volumes())->get('redis-master', 'data');

        expect($volume)->enabled->toBeNull()->size->toBeNull()->storage_class->toBeNull()->claim->toBeNull();
    });

    it('is nothing for a configuration that is not there', function () {
        $app_instance = Fixtures::instance('wordpress');
        $app_instance->updateSetting('configurations.mariadb', null);

        $volume = Volumes::for($app_instance, Fixtures::chart($app_instance)->volumes())->get('mariadb', 'data');

        expect($volume)->enabled->toBeNull()->claim->toBeNull();
    });
});
