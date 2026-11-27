<?php

use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * What each chart gives its release for its volumes, for every shape of the instance's settings
 * the volume code reads today.
 *
 * These pin the CURRENT behaviour, quirks included, before the volumes are moved to one settings
 * location and one chart method (docs/volumes-and-settings-plan.md, Slice 0). They are the
 * safety net for the later slices, so a test here changes only when what a chart is given
 * is meant to change, and says so. Several of them describe things nobody chose on purpose;
 * those are marked "as it is today".
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
});

// The keys of each chart's values that are about its volume, and the volumes of the workloads next to it
const NEXTCLOUD_VOLUME_KEYS = [
    'persistence.enabled', 'persistence.size', 'persistence.storageClass', 'persistence.accessMode', 'persistence.existingClaim',
    'redis.master.persistence', 'redis.replica.persistence',
];

const WORDPRESS_VOLUME_KEYS = [
    'persistence.enabled', 'persistence.size', 'persistence.storageClass', 'persistence.accessModes', 'persistence.existingClaim',
    'mariadb.primary.persistence',
];

describe('Nextcloud', function () {
    it('gives the app one volume sized by the plan, and no claim of its own', function () {
        $values = Fixtures::values(Fixtures::instance('nextcloud'));

        expect(Fixtures::only($values, NEXTCLOUD_VOLUME_KEYS))->toBe([
            'persistence.enabled' => false,
            'persistence.size' => '2Gi',
            'persistence.storageClass' => '',
            'persistence.accessMode' => 'ReadWriteOnce',
            // As it is today: the chart reads setting('existing_claim', ''), and the default of
            // setting() is only used when the instance has no settings at all
            'persistence.existingClaim' => null,
            'redis.master.persistence' => ['enabled' => false, 'storageClass' => ''],
            'redis.replica.persistence' => ['enabled' => false, 'storageClass' => ''],
        ]);
    });

    it('is given a claim that already exists from existing_claim, with the mode and class the instance keeps', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: [
            'existing_claim' => 'restored-data',
            'configurations' => [
                'persistence-accessMode' => 'ReadWriteMany',
                'persistence-storageClass' => 'longhorn-restored',
            ],
        ]);

        $values = Fixtures::values($app_instance);

        expect(Fixtures::only($values, ['persistence.existingClaim', 'persistence.accessMode', 'persistence.storageClass']))->toBe([
            'persistence.existingClaim' => 'restored-data',
            'persistence.accessMode' => 'ReadWriteMany',
            'persistence.storageClass' => 'longhorn-restored',
        ]);
    });

    it('does not read the persistence-existingClaim configuration, which the profile declares', function () {
        // Declared in NextcloudProfile but never read by NextcloudChart: dead configuration
        $app_instance = Fixtures::instance('nextcloud', settings: ['configurations' => ['persistence-existingClaim' => 'from-configuration']]);

        expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->not->toBe('from-configuration');
    });

    it('takes the volume size from chart_storage, which only ever grows', function () {
        $grown = Fixtures::instance('nextcloud', settings: ['chart_storage' => 50]);

        // The plan gives less than the instance already has; the larger stays
        expect(Fixtures::values($grown)['persistence']['size'])->toBe('50Gi')
            ->and($grown->fresh()->setting('chart_storage'))->toBe(50);

        $new = Fixtures::instance('nextcloud');
        Fixtures::values($new);

        // The size the plan gave is kept on the instance as what it was given, in the volume's own place:
        // chart_storage is only read now, for an instance that has one
        expect($new->fresh()->setting('workloads.app.volumes.data.size'))->toBe(2)
            ->and($new->fresh()->setting('chart_storage'))->toBeNull();
    });

    it('passes the Redis volume settings through as the configuration says', function () {
        $redis = [
            'enabled' => true,
            'master' => ['persistence' => ['enabled' => true, 'size' => '3Gi', 'storageClass' => 'fast']],
            'replica' => ['replicaCount' => 1, 'persistence' => ['enabled' => true, 'size' => '1Gi', 'storageClass' => '']],
        ];
        $app_instance = Fixtures::instance('nextcloud');
        // Activation keeps the profile's defaults in the instance's settings; this is the instance's own
        $app_instance->updateSetting('configurations.redis', $redis);

        $values = Fixtures::values($app_instance);

        // Nothing in the blob is a setting of its own: it is the chart's `redis` values as they are
        expect($values['redis'])->toBe($redis);
    });

    it('passes the MariaDB volume settings through as the configuration says', function () {
        $mariadb = ['enabled' => true, 'primary' => ['persistence' => ['enabled' => true, 'existingClaim' => 'db-data', 'size' => '8Gi', 'accessMode' => 'ReadWriteOnce', 'storageClass' => 'fast']]];
        $app_instance = Fixtures::instance('nextcloud');
        $app_instance->updateSetting('configurations.mariadb', $mariadb);

        // As it is today: Nextcloud has a MariaDB blob with a claim of its own, which no setting of the app reads
        expect(Fixtures::values($app_instance)['mariadb'])->toBe($mariadb);
    });
});

describe('WordPress', function () {
    it('gives the app one volume and no claim of its own', function () {
        $values = Fixtures::values(Fixtures::instance('wordpress'));

        expect(Fixtures::only($values, WORDPRESS_VOLUME_KEYS))->toBe([
            'persistence.enabled' => false,
            'persistence.size' => '2Gi',
            'persistence.storageClass' => '',
            'persistence.accessModes' => ['ReadWriteOnce'],
            'persistence.existingClaim' => '',
            'mariadb.primary.persistence' => ['enabled' => false, 'size' => '4Gi', 'storageClass' => ''],
        ]);
    });

    it('is given a claim that already exists from override.pvc.name', function () {
        $app_instance = Fixtures::instance('wordpress', settings: [
            'override' => ['pvc' => ['name' => 'restored-data']],
            'configurations' => [
                'persistence-accessModes' => ['ReadWriteMany'],
                'persistence-storageClass' => 'longhorn-restored',
            ],
        ]);

        expect(Fixtures::only(Fixtures::values($app_instance), ['persistence.existingClaim', 'persistence.accessModes', 'persistence.storageClass']))->toBe([
            'persistence.existingClaim' => 'restored-data',
            'persistence.accessModes' => ['ReadWriteMany'],
            'persistence.storageClass' => 'longhorn-restored',
        ]);
    });

    it('treats the claim a job found on the cluster as an existing claim, as it is today', function () {
        // JobChart::persistentVolumeClaim() writes the claim it finds to override.pvc.name. Nothing says
        // whether a name there was chosen or found, so the next release is given it either way
        $app_instance = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'found-by-a-job']]]);

        expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->toBe('found-by-a-job');
    });

    it('gives the chart every access mode the instance keeps, as a list', function () {
        $app_instance = Fixtures::instance('wordpress', settings: ['configurations' => ['persistence-accessModes' => ['ReadWriteOnce', 'ReadOnlyMany']]]);

        expect(Fixtures::values($app_instance)['persistence']['accessModes'])->toBe(['ReadWriteOnce', 'ReadOnlyMany']);
    });

    it('passes the MariaDB volume settings through as the configuration says', function () {
        $mariadb = [
            'enabled' => true,
            'auth' => ['password' => 'password', 'rootPassword' => 'root_password'],
            'primary' => ['persistence' => ['enabled' => true, 'size' => '8Gi', 'storageClass' => 'fast']],
        ];

        $values = Fixtures::values(Fixtures::instance('wordpress', settings: ['configurations' => ['mariadb' => $mariadb]]));

        expect($values['mariadb'])->toBe($mariadb);
    });
});

describe('CiviCRM', function () {
    it('gives the app one volume and no claim of its own', function () {
        $values = Fixtures::values(Fixtures::instance('civicrm-standalone'));

        // Its MariaDB configuration has no volume settings, unlike WordPress's and Nextcloud's
        expect(Fixtures::only($values, WORDPRESS_VOLUME_KEYS))->toBe([
            'persistence.enabled' => false,
            'persistence.size' => '2Gi',
            'persistence.storageClass' => '',
            'persistence.accessModes' => ['ReadWriteOnce'],
            'persistence.existingClaim' => '',
            'mariadb.primary.persistence' => '(not set)',
        ]);
    });

    it('uses the claim name only when it is told to', function (array $pvc, string $claim) {
        $app_instance = Fixtures::instance('civicrm-standalone', settings: ['override' => ['pvc' => $pvc]]);

        expect(Fixtures::values($app_instance)['persistence']['existingClaim'])->toBe($claim);
    })->with([
        'a name, and told to use it' => [['name' => 'restored-data', 'override' => true], 'restored-data'],
        'a name, not told to' => [['name' => 'found-by-a-job'], ''],
        'a name, told not to' => [['name' => 'old-claim', 'override' => false], ''],
        // Was null while the chart read the name as it was; a claim that is not there is '' now, as for the others
        'told to, with no name' => [['override' => true], ''],
    ]);

    it('wraps the access mode it keeps as one value into a list', function () {
        $app_instance = Fixtures::instance('civicrm-standalone', settings: ['configurations' => ['persistence-accessModes' => 'ReadWriteMany']]);

        expect(Fixtures::values($app_instance)['persistence']['accessModes'])->toBe(['ReadWriteMany']);
    });
});

describe('Generic', function () {
    it('gives the first volume the instance keeps for it', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], [
            'configurations' => [
                'persistence-0-existingClaim' => 'restored-data',
                'persistence-0-accessMode' => 'ReadWriteMany',
                'persistence-0-storageClass' => 'longhorn-restored',
                'persistence-0-size' => 20,
            ],
        ]);

        $persistence = Fixtures::values($app_instance)['persistence'];

        // A claim that exists is mounted and not made, so the size and mode that went with a new one are unused
        expect($persistence)->not->toHaveKey('persistentVolumeClaims')
            ->and($persistence['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe('restored-data');
    });

    it('makes the volume it is not given a claim for, from what the instance keeps for it', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], [
            'configurations' => [
                'persistence-0-accessMode' => 'ReadWriteMany',
                'persistence-0-storageClass' => 'longhorn-restored',
                'persistence-0-size' => 20,
            ],
        ]);

        $values = Fixtures::values($app_instance);

        expect($values['persistence']['persistentVolumeClaims'])->toBe([[
            'name' => 'data',
            'storage' => '20Gi',
            'accessModes' => ['ReadWriteMany'],
            'storageClassName' => 'longhorn-restored',
        ]])
            ->and($values['persistence']['volumes'][0]['persistentVolumeClaim']['claimName'])->toBe($values['fullnameOverride'].'-data');
    });

    it('sizes a volume with no size of its own from the plan', function () {
        $values = Fixtures::values(Fixtures::instance('generic', ['persistence-enabled' => true]));

        expect($values['persistence']['persistentVolumeClaims'][0]['storage'])->toBe('2Gi');
    });

    it('gives each volume its own claim, size and mode', function () {
        $extra = fn (string $field, string $type = 'string') => ['name' => "persistence-1-$field", 'type' => $type, 'persistent' => true, 'additional' => true];

        $app_instance = Fixtures::instance('generic', [
            'persistence-enabled' => true,
            'persistence-1-name' => 'uploads',
            'persistence-1-mountPath' => '/uploads',
            'persistence-1-size' => 5,
            'persistence-1-existingClaim' => '',
        ], [], [
            'persistence-1-name' => $extra('name'),
            'persistence-1-mountPath' => $extra('mountPath'),
            'persistence-1-size' => $extra('size', 'int'),
            'persistence-1-existingClaim' => $extra('existingClaim'),
        ]);

        $persistence = Fixtures::values($app_instance)['persistence'];

        expect(array_column($persistence['persistentVolumeClaims'], 'storage', 'name'))->toBe(['data' => '2Gi', 'uploads' => '5Gi'])
            ->and(array_column($persistence['mounts'], 'mountPath', 'name'))->toBe(['data' => '/data', 'uploads' => '/uploads']);
    });
});

describe('a chart from the catalog', function () {
    // A chart whose volume is at `persistence`, as most are
    $flat = ['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']];

    it('is given the size, and nothing else, while it has no class and no claim to give', function () use ($flat) {
        $values = Fixtures::values(Fixtures::catalogInstance($flat));

        // Only what is written is in the values: the chart's own defaults for the rest are not repeated
        expect($values['persistence'])->toBe(['size' => '2Gi']);
    });

    it('is given a claim that already exists from override.pvc.name, and the class from the plan', function () use ($flat) {
        $app_instance = Fixtures::catalogInstance($flat, ['persistence-storageClass' => 'longhorn-restored'], [
            'override' => ['pvc' => ['name' => 'restored-data']],
        ]);

        $values = Fixtures::values($app_instance);

        expect(Fixtures::only($values, ['persistence.existingClaim', 'persistence.storageClass', 'persistence.size']))->toBe([
            'persistence.existingClaim' => 'restored-data',
            'persistence.storageClass' => 'longhorn-restored',
            'persistence.size' => '2Gi',
        ]);
    });

    it('knows no access mode, whatever the instance keeps for one', function () use ($flat) {
        $app_instance = Fixtures::catalogInstance($flat, [], ['configurations' => ['persistence-accessMode' => 'ReadWriteMany']]);

        expect(Fixtures::values($app_instance)['persistence'])->not->toHaveKeys(['accessMode', 'accessModes']);
    });

    it('is given only the one volume it picks when it has several', function () {
        $app_instance = Fixtures::catalogInstance([
            'persistence' => [
                'data' => ['enabled' => true, 'size' => '10Gi', 'storageClass' => '', 'existingClaim' => ''],
                'cache' => ['enabled' => true, 'size' => '1Gi', 'storageClass' => '', 'existingClaim' => ''],
            ],
        ], [], ['override' => ['pvc' => ['name' => 'restored-data']]]);

        $values = Fixtures::values($app_instance);

        // The first enabled volume is the app's; the other keeps what the chart says
        expect($values['persistence']['data'])->toBe(['size' => '2Gi', 'existingClaim' => 'restored-data'])
            ->and($values['persistence'])->not->toHaveKey('cache');
    });
});
