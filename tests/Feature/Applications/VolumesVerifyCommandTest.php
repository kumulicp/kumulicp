<?php

use App\AppInstance;
use App\Services\AppInstanceService;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * `volumes:verify`: the Helm values of every app, rendered again and compared with an archive from
 * `backup:charts`, to show that a change left them as they were (docs/volumes-and-settings-plan.md).
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
    Storage::fake(config('filesystems.app_backup'));
});

// Starts the next command as a new process would: the application service keeps the first model it sees
// of each instance, and a command loads its own
function newProcess(): void
{
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();
}

// Takes an archive of the apps as they are, and gives its name on the backups disk
function takeArchive(): string
{
    test()->artisan('backup:charts');
    newProcess();

    $stored = Storage::disk(config('filesystems.app_backup'))->files('chart-values');

    return basename(end($stored));
}

// The file in the archive for the instance's first chart
function archivedFile(AppInstance $app_instance): string
{
    $chart = (new AppInstanceService($app_instance->fresh()))->renderedCharts()[0]['name'];

    return "{$app_instance->organization->slug}/{$app_instance->name}/{$chart}.yaml";
}

describe('when nothing differs', function () {
    it('says so and succeeds', function () {
        Fixtures::instance('nextcloud');
        Fixtures::instance('wordpress');
        $archive = takeArchive();

        $this->artisan('volumes:verify', ['archive' => $archive])->assertSuccessful()->expectsOutputToContain('2 files compared, and none differs');
    });

    it('takes the archive as a path, or as a name on the backups disk', function () {
        Fixtures::instance('wordpress');
        $archive = takeArchive();
        $disk = Storage::disk(config('filesystems.app_backup'));

        foreach ([$archive, "chart-values/{$archive}", $disk->path("chart-values/{$archive}")] as $name) {
            newProcess();

            $this->artisan('volumes:verify', ['archive' => $name])->assertSuccessful();
        }
    });

    it('changes nothing in the database', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();
        $settings = AppInstance::find($app_instance->id)->settings;

        $this->artisan('volumes:verify', ['archive' => $archive])->assertSuccessful();

        expect(AppInstance::find($app_instance->id)->settings)->toBe($settings);
    });
});

describe('when something differs', function () {
    it('says which app, which key, and what it was and is, and fails', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();
        $file = archivedFile($app_instance);

        $app_instance->updateSetting('volumes.data.storage_class', 'fast');

        $this->artisan('volumes:verify', ['archive' => $archive])->assertFailed()
            ->expectsOutputToContain($file)
            ->expectsOutputToContain('persistence.storageClass: "" -> "fast"')
            ->expectsOutputToContain('1 differences in 1 files compared');
    });

    it('does not count null and an empty text as different, for a key that is there on both sides', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();

        // What an archive has of a class or a claim a plan stored as null
        $disk = Storage::disk(config('filesystems.app_backup'));
        $zip = new ZipArchive;
        $zip->open($disk->path("chart-values/{$archive}"));
        $file = archivedFile($app_instance);
        $yaml = $zip->getFromName($file);
        expect($yaml)->toContain("storageClass: ''");
        $zip->addFromString($file, str_replace("storageClass: ''", 'storageClass: null', $yaml));
        $zip->close();

        $this->artisan('volumes:verify', ['archive' => $archive])->assertSuccessful();
    });

    it('says a key that is new or gone, and a size that changed', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();

        $app_instance->updateSetting('volumes.data.size', 50);
        $app_instance->updateSetting('volumes.redis-master.enabled', true);

        $this->artisan('volumes:verify', ['archive' => $archive])->assertFailed()
            ->expectsOutputToContain('persistence.size: "2Gi" -> "50Gi"')
            ->expectsOutputToContain('redis.master.persistence.enabled: false -> true');
    });

    it('leaves out the keys it is told are meant to differ', function (array $ignore) {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();

        $app_instance->updateSetting('volumes.data.storage_class', 'fast');
        $app_instance->updateSetting('volumes.redis-master.size', 12);

        $this->artisan('volumes:verify', ['archive' => $archive, '--ignore' => $ignore])->assertSuccessful();
    })->with([
        'the keys' => [['persistence.storageClass', 'redis.master.persistence.size']],
        'patterns' => [['persistence.*', 'redis.*.persistence.size']],
    ]);

    it('fails for a key that is not ignored, though another is', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $archive = takeArchive();

        $app_instance->updateSetting('volumes.data.storage_class', 'fast');
        $app_instance->updateSetting('volumes.data.size', 50);

        $this->artisan('volumes:verify', ['archive' => $archive, '--ignore' => ['persistence.storageClass']])->assertFailed()
            ->expectsOutputToContain('persistence.size: "2Gi" -> "50Gi"')
            ->expectsOutputToContain('1 differences in 1 files compared');
    });

    it('fails for an app that is in the archive and is no longer rendered', function () {
        $app_instance = Fixtures::instance('wordpress');
        Fixtures::instance('nextcloud');
        $archive = takeArchive();
        $file = archivedFile($app_instance);

        $app_instance->forceFill(['status' => 'deactivated'])->save();

        $this->artisan('volumes:verify', ['archive' => $archive])->assertFailed()
            ->expectsOutputToContain("{$file} is in the archive and is no longer rendered");
    });
});

describe('what it lets through', function () {
    it('notes an app that is new since the archive and does not fail for it', function () {
        Fixtures::instance('wordpress');
        $archive = takeArchive();
        $new = Fixtures::instance('nextcloud');

        expect(archivedFile($new))->toContain($new->name);

        $this->artisan('volumes:verify', ['archive' => $archive])->assertSuccessful()
            ->expectsOutputToContain('new since the archive')
            ->expectsOutputToContain('1 files compared, and none differs');
    });

    it('skips an app the archive could not render, and compares the rest', function () {
        Fixtures::instance('wordpress');
        // A volume with no mount path: the generic chart refuses to build it
        Fixtures::instance('generic', ['persistence-enabled' => true, 'persistence-0-mountPath' => '']);
        $archive = takeArchive();

        $this->artisan('volumes:verify', ['archive' => $archive])->assertSuccessful()->expectsOutputToContain('1 files compared, and none differs');
    });

    it('fails for an app it could render when the archive was taken and can not now', function () {
        $app_instance = Fixtures::instance('generic', ['persistence-enabled' => true]);
        $archive = takeArchive();

        // Now the volume has no mount path
        $app_instance->plan->updateSettings(['configurations' => array_merge($app_instance->plan->setting('configurations'), ['persistence-0-mountPath' => ''])]);
        $app_instance->plan->save();
        $app_instance->updateSetting('configurations.persistence-0-mountPath', '');

        $this->artisan('volumes:verify', ['archive' => $archive])->assertFailed()->expectsOutputToContain('can not be rendered now');
    });
});

describe('the archive', function () {
    it('fails for one that is not there', function () {
        $this->artisan('volumes:verify', ['archive' => 'chart-values-nothing.zip'])->assertFailed()->expectsOutputToContain('There is no such archive');
    });

    it('fails for a file that is not a zip', function () {
        Storage::disk(config('filesystems.app_backup'))->put('chart-values/not-a-zip.zip', 'not a zip');

        $this->artisan('volumes:verify', ['archive' => 'not-a-zip.zip'])->assertFailed()->expectsOutputToContain('not a zip archive');
    });
});
