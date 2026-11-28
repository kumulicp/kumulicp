<?php

use App\AppInstance;
use App\Support\Volumes\Volumes;
use Tests\Support\Applications\VolumeFixtures as Fixtures;
use Tests\Support\TestSupports;

/**
 * The volumes of an app instance (docs/volumes-and-settings-plan.md, phase 1): which an app has, what a field
 * is when the instance, its plan and its profile each say something, and what is written where. No chart
 * reads them yet.
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
});

// What an instance keeps for a volume in its own settings
function ownVolume(string $name, array $fields): array
{
    return ['volumes' => [$name => $fields]];
}

describe('which volumes an app has', function () {
    it('is the one every app has, unless its profile says more', function () {
        $volumes = Volumes::for(Fixtures::instance('civicrm-standalone'));

        expect(array_keys($volumes->all()))->toBe(['data'])
            ->and($volumes->get('data'))->followsQuota()->toBeTrue()->takesClaim()->toBeTrue()->optional()->toBeFalse();
    });

    it('is more for an app whose profile says so: Nextcloud\'s Redis and MariaDB, WordPress\'s MariaDB', function () {
        expect(array_keys(Volumes::for(Fixtures::instance('nextcloud'))->all()))->toBe(['data', 'redis-master', 'redis-replica', 'mariadb'])
            ->and(array_keys(Volumes::for(Fixtures::instance('wordpress'))->all()))->toBe(['data', 'mariadb']);
    });

    it('is what its profile declares', function () {
        $volumes = Volumes::for(Fixtures::volumesInstance());

        expect(array_keys($volumes->all()))->toBe(['data', 'redis-master', 'redis-replica', 'mariadb'])
            // Only the one that holds the files takes a claim
            ->and(array_keys(array_filter($volumes->all(), fn ($volume) => $volume->takesClaim())))->toBe(['data'])
            ->and(array_keys(array_filter($volumes->all(), fn ($volume) => $volume->optional())))->toBe(['redis-master', 'redis-replica', 'mariadb']);
    });

    it('is none for the generic app, whose volumes are a list the plan defines', function () {
        expect(Volumes::for(Fixtures::instance('generic'))->all())->toBe([]);
    });

    it('is the one volume of a catalog chart that was detected to have one, and none for one that was not', function () {
        $with = Fixtures::catalogInstance(['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']]);

        expect(array_keys(Volumes::for($with)->all()))->toBe(['data']);
    });

    it('is none for a catalog chart that was not detected to have one', function () {
        $without = Fixtures::catalogInstance(['image' => ['tag' => '1']]);

        expect(Volumes::for($without)->all())->toBe([]);
    });

    it('is the volumes of the chart an instance runs, not of its app', function () {
        [$own, $added] = Fixtures::onTwoCharts(['image' => ['tag' => '1']]);

        // The app's own profile has the volume every app has; the added catalog chart was detected to have none
        expect(array_keys(Volumes::for($own)->all()))->toBe(['data'])
            ->and(Volumes::for($added)->all())->toBe([]);
    })->beforeEach(fn () => (new TestSupports)->seed());
});

describe('what a field is', function () {
    it('is the profile\'s when nothing else says', function () {
        $data = Volumes::for(Fixtures::volumesInstance())->get('data');

        expect($data)->enabled()->toBeFalse()->storageClass()->toBe('')->accessMode()->toBe('ReadWriteOnce');
    });

    it('is the plan\'s over the profile\'s, and the instance\'s over the plan\'s', function () {
        $plan_volumes = ['data' => ['enabled' => true, 'storage_class' => 'from-plan', 'access_mode' => 'ReadWriteMany']];

        $plan_only = Volumes::for(Fixtures::volumesInstance($plan_volumes))->get('data');
        $own = Volumes::for(Fixtures::volumesInstance($plan_volumes, ownVolume('data', ['storage_class' => 'from-instance', 'access_mode' => 'ReadWriteOncePod'])))->get('data');

        expect($plan_only)->enabled()->toBeTrue()->storageClass()->toBe('from-plan')->accessMode()->toBe('ReadWriteMany')
            ->and($own)->enabled()->toBeTrue()->storageClass()->toBe('from-instance')->accessMode()->toBe('ReadWriteOncePod');
    });

    it('is turned off by the instance when the plan turned it on, and the other way round', function () {
        $off = Volumes::for(Fixtures::volumesInstance(['mariadb' => ['enabled' => true]], ownVolume('mariadb', ['enabled' => false])))->get('mariadb');
        $on = Volumes::for(Fixtures::volumesInstance([], ownVolume('data', ['enabled' => true])))->get('data');

        expect($off->enabled())->toBeFalse()
            ->and($on->enabled())->toBeTrue();
    });

    it('is the profile\'s for what an instance leaves null, and an empty class is a class', function () {
        $app_instance = Fixtures::volumesInstance(['data' => ['storage_class' => 'from-plan']], ownVolume('data', ['storage_class' => null, 'access_mode' => null]));

        expect(Volumes::for($app_instance)->get('data'))->storageClass()->toBe('from-plan')->accessMode()->toBe('ReadWriteOnce');

        $app_instance->updateSetting('volumes.data.storage_class', '');

        expect(Volumes::for($app_instance)->get('data')->storageClass())->toBe('');
    });

    it('has none for a volume the profile gives no mode', function () {
        expect(Volumes::for(Fixtures::volumesInstance())->get('redis-master')->accessMode())->toBeNull();
    });

    it('is read for each volume on its own', function () {
        $volumes = Volumes::for(Fixtures::volumesInstance(
            ['redis-master' => ['storage_class' => 'fast']],
            array_replace_recursive(ownVolume('mariadb', ['enabled' => true]), ownVolume('redis-replica', ['storage_class' => 'slow'])),
        ));

        expect($volumes->get('redis-master'))->storageClass()->toBe('fast')->enabled()->toBeFalse()
            ->and($volumes->get('redis-replica'))->storageClass()->toBe('slow')
            ->and($volumes->get('mariadb'))->enabled()->toBeTrue()->storageClass()->toBe('');
    });

    it('works for an instance with no plan', function () {
        $app_instance = Fixtures::volumesInstance();
        $app_instance->forceFill(['plan_id' => null])->save();

        expect(Volumes::for($app_instance->fresh())->get('data'))->enabled()->toBeFalse()->storageClass()->toBe('');
    });
});

describe('the claim', function () {
    it('is the instance\'s alone, whatever the plan says', function () {
        $app_instance = Fixtures::volumesInstance(['data' => ['claim' => 'from-plan']]);

        expect(Volumes::for($app_instance)->get('data')->claim())->toBeNull();

        $app_instance->updateSetting('volumes.data.claim', 'restored-data');

        expect(Volumes::for($app_instance)->get('data')->claim())->toBe('restored-data');
    });

    it('is none for a volume that takes none, whatever the instance keeps', function () {
        $app_instance = Fixtures::volumesInstance(settings: ownVolume('mariadb', ['claim' => 'db-data']));

        expect(Volumes::for($app_instance)->get('mariadb')->claim())->toBeNull();
    });

    it('is none when it is blank', function (?string $claim) {
        $app_instance = Fixtures::volumesInstance(settings: ownVolume('data', ['claim' => $claim]));

        expect(Volumes::for($app_instance)->get('data')->claim())->toBeNull();
    })->with([null, '', '   ']);
});

describe('the size', function () {
    it('is the profile\'s for a volume with a size of its own, the plan\'s over it and the instance\'s over that', function () {
        $profile = Volumes::for(Fixtures::volumesInstance())->get('mariadb');
        $plan = Volumes::for(Fixtures::volumesInstance(['mariadb' => ['size' => 6]]))->get('mariadb');
        $own = Volumes::for(Fixtures::volumesInstance(['mariadb' => ['size' => 6]], ownVolume('mariadb', ['size' => 10])))->get('mariadb');

        expect($profile->size())->toBe(4)
            ->and($plan->size())->toBe(6)
            ->and($own->size())->toBe(10);
    });

    it('does not follow the quota for a volume with a size of its own', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 50]);

        expect(Volumes::for($app_instance)->get('redis-master')->size())->toBe(8)
            ->and($app_instance->fresh()->setting('volumes'))->toBeNull();
    });

    it('is the quota for the volume that follows it, and is kept', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 10]);

        expect(Volumes::for($app_instance)->get('data')->size())->toBe(10)
            ->and($app_instance->fresh()->setting('volumes.data.size'))->toBe(10);
    });

    it('only grows, since a claim can\'t be made smaller', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 10]);
        $data = Volumes::for($app_instance)->get('data');

        expect($data->size())->toBe(10);

        // The subscription gives less now; the volume keeps what it was given
        $app_instance->updateSetting('storage_quota', 4);
        expect($data->size())->toBe(10);

        $app_instance->updateSetting('storage_quota', 25);
        expect($data->size())->toBe(25)
            ->and($app_instance->fresh()->setting('volumes.data.size'))->toBe(25);
    });

    it('ignores the plan\'s size for the volume that follows the quota', function () {
        $app_instance = Fixtures::volumesInstance(['data' => ['size' => 100]], ['storage_quota' => 3]);

        expect(Volumes::for($app_instance)->get('data')->size())->toBe(3);
    });

    it('is the storage of the release the chart is given, and keeps nothing', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 10]);

        expect(Volumes::for($app_instance, storage: 7)->get('data')->size())->toBe(7)
            ->and($app_instance->fresh()->setting('volumes'))->toBeNull()
            // Not given any, it is the instance's own
            ->and(Volumes::for($app_instance, storage: 0)->get('data')->size())->toBe(10);
    });

    it('starts from what the instance was given', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['storage_quota' => 2] + ownVolume('data', ['size' => 50]));

        expect(Volumes::for($app_instance)->get('data')->size())->toBe(50)
            ->and($app_instance->fresh()->setting('volumes.data.size'))->toBe(50);
    });
});

describe('writing', function () {
    it('writes the fields given in the volume\'s place in the instance\'s settings and leaves the rest alone', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['other' => 'kept']);

        Volumes::for($app_instance)->assign('mariadb', ['enabled' => true, 'size' => '10', 'storage_class' => 'fast', 'access_mode' => 'ReadWriteMany']);

        $settings = $app_instance->fresh()->settings;
        expect($settings['volumes']['mariadb'])->toBe(['enabled' => true, 'size' => 10, 'storage_class' => 'fast', 'access_mode' => 'ReadWriteMany'])
            ->and($settings['other'])->toBe('kept')
            ->and($settings['volumes'])->not->toHaveKey('data');
    });

    it('is read back at once', function () {
        $app_instance = Fixtures::volumesInstance();
        $volumes = Volumes::for($app_instance);

        $volumes->assign('data', ['claim' => 'restored-data', 'storage_class' => 'fast', 'size' => 40]);

        expect($volumes->get('data'))->claim()->toBe('restored-data')->storageClass()->toBe('fast')->size()->toBe(40);
    });

    it('gives back what each field held, which restore puts back', function () {
        $app_instance = Fixtures::volumesInstance(settings: ownVolume('data', ['claim' => 'old-claim', 'size' => 4]));
        $volumes = Volumes::for($app_instance);

        $before = $volumes->assign('data', ['claim' => 'restored-data', 'size' => 40, 'access_mode' => 'ReadWriteMany']);

        expect($before)->toBe([
            'volumes.data.claim' => ['value' => 'old-claim'],
            'volumes.data.size' => ['value' => 4],
            'volumes.data.access_mode' => null,
        ]);

        // It goes through the task's saved values on the way
        $volumes->restore(json_decode(json_encode($before), true));

        expect($app_instance->fresh()->settings['volumes']['data'])->toBe(['claim' => 'old-claim', 'size' => 4]);
    });

    it('forgets a field that is given as null, so what the plan says applies again', function () {
        $app_instance = Fixtures::volumesInstance(['data' => ['storage_class' => 'from-plan']], ownVolume('data', ['storage_class' => 'own']));
        $volumes = Volumes::for($app_instance);

        $volumes->assign('data', ['storage_class' => null]);

        expect($volumes->get('data')->storageClass())->toBe('from-plan');
    });

    it('turns a blank claim into none, and a number kept as text into a number', function () {
        $app_instance = Fixtures::volumesInstance();

        Volumes::for($app_instance)->assign('data', ['claim' => '  ', 'size' => '12', 'enabled' => 1, 'storage_class' => '']);

        expect($app_instance->fresh()->settings['volumes']['data'])->toBe(['claim' => null, 'size' => 12, 'enabled' => true, 'storage_class' => '']);
    });

    it('can be put back for several volumes at once', function () {
        $app_instance = Fixtures::volumesInstance();
        $volumes = Volumes::for($app_instance);

        $before = $volumes->assign('data', ['claim' => 'restored-data']) + $volumes->assign('mariadb', ['enabled' => true]);
        $volumes->restore($before);

        expect($app_instance->fresh()->settings['volumes'])->toBe(['data' => [], 'mariadb' => []]);
    });

    it('refuses a field a volume does not have', function () {
        Volumes::for(Fixtures::volumesInstance())->assign('data', ['colour' => 'blue']);
    })->throws(InvalidArgumentException::class, 'no colour');

    it('refuses a volume the profile did not declare', function () {
        Volumes::for(Fixtures::volumesInstance())->assign('redis', ['size' => 3]);
    })->throws(InvalidArgumentException::class, "no volume 'redis'");

    it('refuses to give a claim to a volume that takes none', function () {
        Volumes::for(Fixtures::volumesInstance())->assign('redis-master', ['claim' => 'restored-data']);
    })->throws(LogicException::class, 'can not be given a claim');

    it('lets a volume that takes none be told it has none', function () {
        $app_instance = Fixtures::volumesInstance();

        Volumes::for($app_instance)->assign('redis-master', ['claim' => null, 'size' => 3]);

        expect(Volumes::for($app_instance)->get('redis-master'))->claim()->toBeNull()->size()->toBe(3);
    });
});

it('leaves what the instance keeps for other things alone', function () {
    $app_instance = Fixtures::volumesInstance(settings: ['existing_claim' => 'old-claim', 'chart_storage' => 50, 'override' => ['pvc' => ['name' => 'found']]]);
    $before = AppInstance::find($app_instance->id)->settings;

    $volumes = Volumes::for($app_instance);
    $volumes->get('data')->claim();
    $volumes->get('data')->size();

    // The old places are not read, and never written: nothing here reads them
    expect($volumes->get('data'))->claim()->toBeNull()
        ->and(AppInstance::find($app_instance->id)->settings)->toMatchArray(['existing_claim' => 'old-claim', 'chart_storage' => 50, 'override' => ['pvc' => ['name' => 'found']]]);
});
