<?php

use App\AppInstance;
use App\AppPlan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * `volumes:migrate`: the volume settings of the plans and instances, copied into `volumes.<name>` from the
 * places each chart kept them (docs/volumes-and-settings-plan.md, "The migration").
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
});

// What is stored for an instance, as the command reads it
function storedSettings(AppInstance $app_instance): array
{
    return json_decode(DB::table('app_instances')->where('id', $app_instance->id)->value('settings'), true) ?? [];
}

function storedPlanSettings(AppPlan $plan): array
{
    return json_decode(DB::table('app_plans')->where('id', $plan->id)->value('settings'), true) ?? [];
}

// A Nextcloud instance as it is kept today
function legacyNextcloud(array $plan_configurations = [], array $settings = []): AppInstance
{
    return Fixtures::instance('nextcloud', $plan_configurations, $settings);
}

describe('what it writes', function () {
    it('copies an instance\'s volume settings into volumes.<name>, and leaves the old ones', function () {
        $app_instance = legacyNextcloud(settings: [
            'existing_claim' => 'restored-data',
            'chart_storage' => 40,
            'configurations' => ['persistence-storageClass' => 'fast', 'persistence-enabled' => true],
        ]);

        $this->artisan('volumes:migrate')->assertSuccessful()->expectsOutputToContain('Wrote volumes to');

        $settings = storedSettings($app_instance);

        expect($settings['volumes']['data'])->toMatchArray(['claim' => 'restored-data', 'size' => 40, 'storage_class' => 'fast', 'enabled' => true])
            // The old places stay, so that the code that read them can be rolled back to
            ->and($settings)->toMatchArray(['existing_claim' => 'restored-data', 'chart_storage' => 40])
            ->and($settings['configurations'])->toMatchArray(['persistence-storageClass' => 'fast', 'persistence-enabled' => true]);
    });

    it('copies a plan\'s too, and leaves an instance following it', function () {
        $app_instance = legacyNextcloud(['persistence-storageClass' => 'longhorn'], ['configurations' => ['persistence-storageClass' => 'longhorn']]);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedPlanSettings($app_instance->plan)['volumes']['data'])->toMatchArray(['storage_class' => 'longhorn'])
            // The same as the plan's, so it is not copied
            ->and(storedSettings($app_instance)['volumes']['data'] ?? [])->not->toHaveKey('storage_class');
    });

    it('keeps what is the instance\'s own when it differs from the plan', function () {
        $app_instance = legacyNextcloud(['persistence-storageClass' => 'longhorn'], ['configurations' => ['persistence-storageClass' => 'fast']]);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedSettings($app_instance)['volumes']['data']['storage_class'])->toBe('fast');
    });

    it('moves the Redis and MariaDB volumes out of the configurations the chart takes as they are', function () {
        $app_instance = legacyNextcloud(settings: ['configurations' => [
            'redis' => ['master' => ['persistence' => ['enabled' => true, 'size' => '3Gi', 'storageClass' => 'fast']]],
        ]]);

        $this->artisan('volumes:migrate')->assertSuccessful();

        $settings = storedSettings($app_instance);

        expect($settings['volumes']['redis-master'])->toBe(['enabled' => true, 'size' => 3, 'storage_class' => 'fast'])
            // The configuration is not changed
            ->and($settings['configurations']['redis']['master']['persistence'])->toEqual(['enabled' => true, 'size' => '3Gi', 'storageClass' => 'fast']);
    });

    it('reads each app by the chart that deploys it', function () {
        $wordpress = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'wp-data']], 'configurations' => ['persistence-accessModes' => ['ReadWriteMany']]]);
        $civicrm = Fixtures::instance('civicrm-standalone', settings: ['override' => ['pvc' => ['name' => 'found-by-a-job']]]);
        $catalog = Fixtures::catalogInstance(['persistence' => ['enabled' => true, 'storageClass' => '', 'size' => '10Gi', 'existingClaim' => '']], settings: ['override' => ['pvc' => ['name' => 'catalog-data']]]);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedSettings($wordpress)['volumes']['data'])->toMatchArray(['claim' => 'wp-data', 'access_mode' => 'ReadWriteMany'])
            // Not told to use it: a name a job found
            ->and(storedSettings($civicrm)['volumes']['data'] ?? [])->not->toHaveKey('claim')
            ->and(storedSettings($catalog)['volumes']['data'])->toMatchArray(['claim' => 'catalog-data']);
    });

    it('moves chart_storage of an app that has no layout of its own, a plugin\'s for one', function () {
        $app_instance = Fixtures::volumesInstance(settings: ['chart_storage' => 30, 'existing_claim' => 'not-read']);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedSettings($app_instance)['volumes'])->toBe(['data' => ['size' => 30]]);
    });

    it('leaves the generic app alone, whose volumes are migrated on their own', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true], ['chart_storage' => 30]);
        $before = storedSettings($app_instance);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedSettings($app_instance))->toBe($before);
    });
});

describe('how it runs', function () {
    it('writes nothing for a dry run, and says what it would', function () {
        $app_instance = legacyNextcloud(settings: ['existing_claim' => 'restored-data']);
        $before = storedSettings($app_instance);

        $this->artisan('volumes:migrate --dry-run')->assertSuccessful()->expectsOutputToContain('Would write volumes to');

        expect(storedSettings($app_instance))->toBe($before);
    });

    it('can be run again, and the second time has nothing to add', function () {
        $app_instance = legacyNextcloud(settings: ['existing_claim' => 'restored-data', 'chart_storage' => 40]);

        $this->artisan('volumes:migrate')->assertSuccessful();
        $first = storedSettings($app_instance);

        $this->artisan('volumes:migrate')->assertSuccessful()->expectsOutputToContain('Wrote volumes to 0 plans and 0 instances');

        expect(storedSettings($app_instance))->toBe($first);
    });

    it('never writes over a volume setting that is already there', function () {
        $app_instance = legacyNextcloud(settings: ['existing_claim' => 'old-claim', 'volumes' => ['data' => ['claim' => 'already-chosen']]]);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(storedSettings($app_instance)['volumes']['data']['claim'])->toBe('already-chosen');
    });

    it('writes the settings directly, so nothing that listens for a saved instance runs and its time does not move', function () {
        $app_instance = legacyNextcloud(settings: ['existing_claim' => 'restored-data']);
        DB::table('app_instances')->where('id', $app_instance->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->artisan('volumes:migrate')->assertSuccessful();

        expect(DB::table('app_instances')->where('id', $app_instance->id)->value('updated_at'))->toBe('2020-01-01 00:00:00');
    });

    it('says what a person has to look at, by instance', function () {
        $wordpress = Fixtures::instance('wordpress', settings: ['override' => ['pvc' => ['name' => 'wp-data']]]);
        $nextcloud = legacyNextcloud(settings: ['configurations' => ['mariadb' => ['primary' => ['persistence' => ['existingClaim' => 'db-data', 'size' => '500Mi']]]]]);

        $wordpress_line = "instance {$wordpress->id} ({$wordpress->label}, organization {$wordpress->organization_id}): ";
        $nextcloud_line = "instance {$nextcloud->id} ({$nextcloud->label}, organization {$nextcloud->organization_id}): configurations.mariadb.primary.persistence";

        // One line for each thing to look at, naming the instance
        $this->artisan('volumes:migrate')->assertSuccessful()
            ->expectsOutputToContain($wordpress_line."override.pvc.name is kept as the claim ('wp-data')")
            ->expectsOutputToContain($nextcloud_line.'.size (500Mi) is not a whole number of Gi: rounded up to 1Gi')
            ->expectsOutputToContain($nextcloud_line.'.existingClaim is not kept: only the data volume takes a claim');
    });
});
