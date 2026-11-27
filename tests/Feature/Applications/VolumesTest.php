<?php

use App\Support\Volumes\Volumes;
use App\Support\Volumes\VolumeSpec;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;

/**
 * The store of an app's volumes (docs/volumes-and-settings-plan.md, Slice 1): where each field
 * is read from, in what order, and what is written where. Nothing here is wired to a chart yet.
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
});

// The volumes of a chart with a Redis and a MariaDB of its own next to the app's
function volumesOfThreeWorkloads(): array
{
    return [
        VolumeSpec::primary(),
        new VolumeSpec('redis-master', 'data', optional: true, default_size: 2),
        new VolumeSpec('redis-replica', 'data', optional: true, claim_mode: 'by_name'),
        new VolumeSpec('mariadb', 'data', optional: true, default_size: 8),
    ];
}

describe('what an app has', function () {
    it('is the one volume every chart has, until the chart says more', function () {
        $volumes = Volumes::for(Fixtures::instance('nextcloud'));

        expect(array_keys($volumes->specs()))->toBe(['app/data'])
            ->and($volumes->primary()->spec->primary)->toBeTrue()
            ->and(array_keys($volumes->all()))->toBe(['app/data']);
    });

    it('is what the chart declares', function () {
        $volumes = Volumes::for(Fixtures::instance('nextcloud'), volumesOfThreeWorkloads());

        expect(array_keys($volumes->all()))->toBe(['app/data', 'redis-master/data', 'redis-replica/data', 'mariadb/data'])
            ->and($volumes->primary()->id())->toBe('app/data')
            ->and($volumes->get('mariadb', 'data')->spec->default_size)->toBe(8);
    });

    it('refuses a chart that declares a volume twice, or two primary ones', function (array $specs) {
        Volumes::for(Fixtures::instance('nextcloud'), $specs);
    })->with([
        'twice' => [[new VolumeSpec('redis', 'data'), new VolumeSpec('redis', 'data')]],
        'two primary' => [[VolumeSpec::primary(), new VolumeSpec('other', 'data', primary: true)]],
    ])->throws(InvalidArgumentException::class);

    it('has no volume the chart did not declare', function () {
        Volumes::for(Fixtures::instance('nextcloud'))->get('redis-master', 'data');
    })->throws(InvalidArgumentException::class, 'no volume redis-master/data');

    it('has no primary volume when the chart declares none', function () {
        Volumes::for(Fixtures::instance('nextcloud'), [new VolumeSpec('redis', 'data')])->primary();
    })->throws(LogicException::class);
});

describe('reading', function () {
    it('takes what the instance keeps for the volume over where the chart used to keep it, and over the plan', function () {
        $app_instance = Fixtures::instance('nextcloud', ['persistence-storageClass' => 'from-plan'], [
            'existing_claim' => 'old-claim',
            'chart_storage' => 4,
            'workloads' => ['app' => ['volumes' => ['data' => [
                'enabled' => true, 'size' => 9, 'storage_class' => 'fast', 'access_mode' => 'ReadWriteMany', 'claim' => 'restored-data', 'discovered' => 'found',
            ]]]],
        ]);

        $volume = Volumes::for($app_instance)->primary();

        expect($volume)->enabled->toBeTrue()->size->toBe(9)->storage_class->toBe('fast')
            ->access_mode->toBe('ReadWriteMany')->claim->toBe('restored-data')->discovered->toBe('found');
    });

    it('takes what it has been told there is none of over where the chart used to keep it', function () {
        // What a move to a new volume writes: the old volume's claim is not the new one's
        $app_instance = Fixtures::instance('nextcloud', settings: [
            'existing_claim' => 'old-claim',
            'workloads' => ['app' => ['volumes' => ['data' => ['claim' => '']]]],
        ]);

        expect(Volumes::for($app_instance)->primary()->claim)->toBeNull();
    });

    it('takes the plan\'s and the profile\'s values for what nothing has been kept for', function () {
        $app_instance = Fixtures::instance('nextcloud', ['persistence-enabled' => true], [
            'configurations' => ['persistence-storageClass' => 'longhorn', 'persistence-accessMode' => 'ReadWriteMany'],
        ]);

        $volume = Volumes::for($app_instance)->primary();

        expect($volume)->enabled->toBeTrue()->storage_class->toBe('longhorn')->access_mode->toBe('ReadWriteMany')
            ->claim->toBeNull()->discovered->toBeNull()->size->toBeNull();
    });

    it('takes the first of the access modes WordPress keeps as a list, and has them all', function () {
        $app_instance = Fixtures::instance('wordpress', settings: ['configurations' => ['persistence-accessModes' => ['ReadWriteMany', 'ReadOnlyMany']]]);

        expect(Volumes::for($app_instance)->primary())->access_mode->toBe('ReadWriteMany')->access_modes->toBe(['ReadWriteMany', 'ReadOnlyMany']);
    });

    it('has the one access mode it keeps as the only one', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['workloads' => ['app' => ['volumes' => ['data' => ['access_mode' => 'ReadWriteMany']]]]]);
        $none = Fixtures::instance('civicrm-standalone', settings: ['configurations' => ['persistence-accessModes' => '']]);

        expect(Volumes::for($app_instance)->primary())->access_mode->toBe('ReadWriteMany')->access_modes->toBe(['ReadWriteMany'])
            ->and(Volumes::for($none)->primary())->access_modes->toBe([]);
    });

    it('keeps the storage class the chart is left to choose as it is, not as none', function () {
        expect(Volumes::for(Fixtures::instance('nextcloud'))->primary()->storage_class)->toBe('');
    });

    it('is a claim a job mounts when it was chosen, else when it was found', function () {
        $chosen = Fixtures::instance('nextcloud', settings: ['workloads' => ['app' => ['volumes' => ['data' => ['claim' => 'chosen', 'discovered' => 'found']]]]]);
        $found = Fixtures::instance('nextcloud', settings: ['workloads' => ['app' => ['volumes' => ['data' => ['discovered' => 'found']]]]]);
        $none = Fixtures::instance('nextcloud');

        expect(Volumes::for($chosen)->claimName('app', 'data'))->toBe('chosen')
            ->and(Volumes::for($found)->claimName('app', 'data'))->toBe('found')
            ->and(Volumes::for($none)->claimName('app', 'data'))->toBeNull();
    });
});

describe('reading from where each chart used to keep the volume', function () {
    it('finds Nextcloud\'s claim and size', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'restored-data', 'chart_storage' => 30]);

        expect(Volumes::for($app_instance)->primary())->claim->toBe('restored-data')->size->toBe(30);
    });

    it('finds WordPress\'s claim, a job\'s finding included, as it did', function () {
        $app_instance = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'restored-data']], 'chart_storage' => 30]);

        expect(Volumes::for($app_instance)->primary())->claim->toBe('restored-data')->discovered->toBeNull()->size->toBe(30);
    });

    it('finds CiviCRM\'s claim only when it was told to be used, else it is one a job found', function (array $pvc, ?string $claim, ?string $discovered) {
        $app_instance = Fixtures::instance('civicrm-standalone', settings: ['override' => ['pvc' => $pvc]]);

        expect(Volumes::for($app_instance)->primary())->claim->toBe($claim)->discovered->toBe($discovered);
    })->with([
        'chosen' => [['name' => 'restored-data', 'override' => true], 'restored-data', null],
        'found' => [['name' => 'found-by-a-job'], null, 'found-by-a-job'],
        'told not to' => [['name' => 'old-claim', 'override' => false], null, 'old-claim'],
        'told to, with no name' => [['override' => true], null, null],
    ]);

    it('finds a catalog chart\'s claim, and no access mode', function () {
        $app_instance = Fixtures::catalogInstance(
            ['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']],
            [],
            ['override' => ['pvc' => ['name' => 'restored-data']], 'chart_storage' => 30],
        );

        expect(Volumes::for($app_instance)->primary())->claim->toBe('restored-data')->size->toBe(30)->access_mode->toBeNull();
    });

    it('finds the generic chart\'s volume by its name, with its own size', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], [
            'configurations' => [
                'persistence-0-existingClaim' => 'restored-data',
                'persistence-0-accessMode' => 'ReadWriteMany',
                'persistence-0-storageClass' => 'longhorn-restored',
                'persistence-0-size' => 20,
            ],
            'chart_storage' => 4,
        ]);

        expect(Volumes::for($app_instance)->primary())
            ->claim->toBe('restored-data')->access_mode->toBe('ReadWriteMany')
            ->storage_class->toBe('longhorn-restored')->size->toBe(20);
    });

    it('sizes a generic volume with no size of its own as the app is', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], ['chart_storage' => 7]);

        expect(Volumes::for($app_instance)->primary())->size->toBe(7)->claim->toBeNull();
    });

    it('does not find a volume the generic chart has no item of that name for', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], ['configurations' => ['persistence-0-existingClaim' => 'restored-data']]);

        $volumes = Volumes::for($app_instance, [new VolumeSpec('app', 'uploads', primary: true)]);

        expect($volumes->primary())->claim->toBeNull();
    });

    it('reads the layout of the chart the instance runs, not of its app', function () {
        [$own, $added] = Fixtures::onTwoCharts(
            ['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']],
            ['override' => ['pvc' => ['name' => 'restored-data']]],
        );

        // The app's own chart has no old place for it; the added catalog chart's is override.pvc.name
        expect(Volumes::for($own)->primary()->claim)->toBeNull()
            ->and(Volumes::for($added)->primary()->claim)->toBe('restored-data');
    })->beforeEach(fn () => (new TestSupports)->seed());

    it('does not look for the volumes of other workloads in the old places', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'restored-data', 'chart_storage' => 30]);

        $volumes = Volumes::for($app_instance, volumesOfThreeWorkloads());

        // Nothing is kept for it, so it is its chart's size: not the 30 the app's volume has kept
        expect($volumes->get('redis-master', 'data'))->claim->toBeNull()->size->toBeNull()
            ->and($volumes->size('redis-master', 'data'))->toBe(2);
    });
});

describe('the volumes of other workloads', function () {
    it('are off, and their chart\'s to size, until they are turned on', function () {
        $volumes = Volumes::for(Fixtures::instance('nextcloud'), volumesOfThreeWorkloads());

        // No size is kept for them; the size they are made with is the chart's default
        expect($volumes->get('redis-master', 'data'))->enabled->toBeFalse()->size->toBeNull()->claim->toBeNull()
            ->and($volumes->size('redis-master', 'data'))->toBe(2)
            ->and($volumes->get('mariadb', 'data'))->enabled->toBeFalse()->size->toBeNull()
            ->and($volumes->size('mariadb', 'data'))->toBe(8);
    });

    it('are what the instance keeps for them, each under its own workload', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['workloads' => [
            'redis-master' => ['volumes' => ['data' => ['enabled' => true, 'size' => 3, 'storage_class' => 'fast']]],
            'mariadb' => ['volumes' => ['data' => ['enabled' => true, 'claim' => 'db-data']]],
        ]]);

        $volumes = Volumes::for($app_instance, volumesOfThreeWorkloads());

        expect($volumes->get('redis-master', 'data'))->enabled->toBeTrue()->size->toBe(3)->storage_class->toBe('fast')->claim->toBeNull()
            ->and($volumes->get('mariadb', 'data'))->enabled->toBeTrue()->claim->toBe('db-data')->size->toBeNull()
            ->and($volumes->size('mariadb', 'data'))->toBe(8)
            ->and($volumes->get('redis-replica', 'data'))->enabled->toBeFalse()
            ->and($volumes->primary())->claim->toBeNull();
    });

    it('take the plan\'s settings for the workload, named after it', function () {
        $app_instance = Fixtures::instance('nextcloud', [
            'persistence-redis-master-storageClass' => 'from-plan',
            'persistence-redis-master-size' => 5,
            'persistence-mariadb-enabled' => true,
        ]);

        $volumes = Volumes::for($app_instance, volumesOfThreeWorkloads());

        expect($volumes->get('redis-master', 'data'))->storage_class->toBe('from-plan')->size->toBe(5)
            ->and($volumes->get('mariadb', 'data'))->enabled->toBeTrue()
            ->and($volumes->get('redis-replica', 'data'))->storage_class->toBeNull();
    });
});

describe('the size', function () {
    it('is the quota when nothing has been given, and is kept in the new place', function () {
        $app_instance = Fixtures::instance('nextcloud');

        expect(Volumes::for($app_instance)->size('app', 'data', 2))->toBe(2);

        $settings = $app_instance->fresh()->settings;
        expect($settings['workloads']['app']['volumes']['data']['size'])->toBe(2)
            ->and($settings)->not->toHaveKey('chart_storage');
    });

    it('only grows, since a claim can\'t be made smaller', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $volumes = Volumes::for($app_instance);

        expect($volumes->size('app', 'data', 2))->toBe(2)
            ->and($volumes->size('app', 'data', 1))->toBe(2)
            ->and($volumes->size('app', 'data', 5))->toBe(5)
            ->and($volumes->size('app', 'data', 3))->toBe(5)
            ->and(Volumes::for($app_instance->fresh())->size('app', 'data'))->toBe(5);
    });

    it('starts from what the chart had kept, and leaves it as it was while it is enough', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['chart_storage' => 50]);

        expect(Volumes::for($app_instance)->size('app', 'data', 2))->toBe(50)
            ->and($app_instance->fresh()->settings)->not->toHaveKey('workloads');

        expect(Volumes::for($app_instance)->size('app', 'data', 80))->toBe(80)
            ->and($app_instance->fresh()->settings['workloads']['app']['volumes']['data']['size'])->toBe(80)
            // The old place is not written to
            ->and($app_instance->fresh()->settings['chart_storage'])->toBe(50);
    });

    it('is the smallest size, not 0, when nothing is worked out yet', function () {
        $app_instance = Fixtures::instance('nextcloud');

        expect(Volumes::for($app_instance)->size('app', 'data', 0))->toBe(1)
            ->and($app_instance->fresh()->settings)->not->toHaveKey('workloads');
    });

    it('is its own for a volume with a size of its own, whatever the quota', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['workloads' => ['mariadb' => ['volumes' => ['data' => ['size' => 12]]]]]);
        $volumes = Volumes::for($app_instance, volumesOfThreeWorkloads());

        expect($volumes->size('mariadb', 'data', 100))->toBe(12)
            // The one that has none is its chart's default
            ->and($volumes->size('redis-master', 'data', 100))->toBe(2)
            ->and($app_instance->fresh()->settings['workloads'])->not->toHaveKey('redis-master');
    });
});

describe('writing', function () {
    it('writes the fields given to the volume\'s place in the settings and leaves the rest alone', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['other' => 'kept']);

        (new Volumes($app_instance, volumesOfThreeWorkloads()))->assign('mariadb', 'data', ['enabled' => true, 'size' => '10', 'claim' => 'db-data', 'access_mode' => 'ReadWriteOnce']);

        $settings = $app_instance->fresh()->settings;
        expect($settings['workloads']['mariadb']['volumes']['data'])->toBe(['enabled' => true, 'size' => 10, 'claim' => 'db-data', 'access_mode' => 'ReadWriteOnce'])
            ->and($settings['other'])->toBe('kept')
            ->and($settings['workloads'])->not->toHaveKey('app');
    });

    it('never writes where the chart used to keep the volume', function () {
        $app_instance = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'old-claim']], 'chart_storage' => 4]);

        Volumes::for($app_instance)->assign('app', 'data', ['claim' => 'restored-data', 'size' => 40]);

        $settings = $app_instance->fresh()->settings;
        expect($settings['override']['pvc']['name'])->toBe('old-claim')
            ->and($settings['chart_storage'])->toBe(4)
            // And it is the new one that is read from now on
            ->and(Volumes::for($app_instance->fresh())->primary())->claim->toBe('restored-data')->size->toBe(40);
    });

    it('gives back what each field held, which restore puts back', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'old-claim', 'chart_storage' => 4]);
        $volumes = Volumes::for($app_instance);
        $before_settings = $app_instance->settings;

        $before = $volumes->assign('app', 'data', ['claim' => 'restored-data', 'size' => 40, 'access_mode' => 'ReadWriteMany']);

        expect($before)->toBe([
            'workloads.app.volumes.data.claim' => null,
            'workloads.app.volumes.data.size' => null,
            'workloads.app.volumes.data.access_mode' => null,
        ]);

        // It goes through the task's saved values on the way
        $volumes->restore(json_decode(json_encode($before), true));

        expect($app_instance->fresh()->settings['workloads']['app']['volumes']['data'])->toBe([])
            ->and(Volumes::for($app_instance->fresh())->primary())->claim->toBe('old-claim')->size->toBe(4);
    });

    it('puts back the values it replaced when it is written again', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $volumes = Volumes::for($app_instance);

        $volumes->assign('app', 'data', ['claim' => 'first', 'size' => 10]);
        $before = $volumes->assign('app', 'data', ['claim' => 'second', 'size' => 20]);

        expect($before)->toBe([
            'workloads.app.volumes.data.claim' => ['value' => 'first'],
            'workloads.app.volumes.data.size' => ['value' => 10],
        ]);

        $volumes->restore($before);

        expect(Volumes::for($app_instance->fresh())->primary())->claim->toBe('first')->size->toBe(10);
    });

    it('is a decision that there is no claim when it is given none, which wins over the old place', function () {
        $app_instance = Fixtures::instance('nextcloud', settings: ['existing_claim' => 'old-claim']);
        $volumes = Volumes::for($app_instance);

        $before = $volumes->assign('app', 'data', ['claim' => null]);

        expect(Volumes::for($app_instance->fresh())->primary()->claim)->toBeNull();

        $volumes->restore($before);

        expect(Volumes::for($app_instance->fresh())->primary()->claim)->toBe('old-claim');
    });

    it('turns a blank claim into none, and a number kept as text into a number', function () {
        $app_instance = Fixtures::instance('nextcloud');

        Volumes::for($app_instance)->assign('app', 'data', ['claim' => '  ', 'size' => '12', 'enabled' => 1, 'storage_class' => '']);

        expect($app_instance->fresh()->settings['workloads']['app']['volumes']['data'])
            ->toBe(['claim' => null, 'size' => 12, 'enabled' => true, 'storage_class' => '']);
    });

    it('refuses a field a volume does not have', function () {
        Volumes::for(Fixtures::instance('nextcloud'))->assign('app', 'data', ['colour' => 'blue']);
    })->throws(InvalidArgumentException::class, 'no colour');

    it('refuses a volume the chart did not declare', function () {
        Volumes::for(Fixtures::instance('nextcloud'))->assign('redis-master', 'data', ['size' => 3]);
    })->throws(InvalidArgumentException::class);

    it('refuses to give a claim to a volume that can\'t take one', function (string $claim_mode) {
        $volumes = Volumes::for(Fixtures::instance('nextcloud'), [new VolumeSpec('redis-replica', 'data', claim_mode: $claim_mode)]);

        $volumes->assign('redis-replica', 'data', ['claim' => 'restored-data']);
    })->with(['by_name', 'none'])->throws(LogicException::class, 'can not be given a claim');

    it('can still say a volume that can\'t take a claim has none, and set the rest', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $volumes = Volumes::for($app_instance, [new VolumeSpec('redis-replica', 'data', claim_mode: 'none')]);

        $volumes->assign('redis-replica', 'data', ['claim' => null, 'size' => 3]);

        expect($volumes->get('redis-replica', 'data'))->claim->toBeNull()->size->toBe(3);
    });
});
