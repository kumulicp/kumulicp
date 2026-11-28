<?php

use App\AppInstance;
use App\Services\AppInstanceService;
use App\Support\CoreVersion;
use App\Support\Facades\Application;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Applications\VolumeFixtures as Fixtures;

/**
 * `backup:charts`: a zip of YAML files with the Helm values of every app, which an admin looks at before a
 * change that should leave them as they are, and after it. See docs/volumes-and-settings-plan.md.
 */
beforeEach(function () {
    // The directory is not what these are about, and not every machine that runs them has one
    setupAccountManagerDriver('db');
    Storage::fake(config('filesystems.app_backup'));
});

// The one archive the command wrote, as [path in the zip => its contents]
function backupChartsArchive(): array
{
    $disk = Storage::disk(config('filesystems.app_backup'));
    $stored = $disk->files('chart-values');

    expect($stored)->toHaveCount(1);

    $zip = new ZipArchive;
    $zip->open($disk->path($stored[0]));

    $files = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $files[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
    }
    $zip->close();

    return $files;
}

function backupChartsManifest(array $files): array
{
    return json_decode($files['manifest.json'], true);
}

describe('what it writes', function () {
    it('is one zip on the backups disk, with two files for each chart of each app and a manifest', function () {
        $nextcloud = Fixtures::instance('nextcloud');
        $wordpress = Fixtures::instance('wordpress');

        $this->artisan('backup:charts')->assertSuccessful()->expectsOutputToContain('chart-values-');

        $files = backupChartsArchive();
        $manifest = backupChartsManifest($files);

        $expected = collect([$nextcloud, $wordpress])->flatMap(function (AppInstance $app_instance) {
            $folder = "{$app_instance->organization->slug}/{$app_instance->name}";
            $chart = (new AppInstanceService($app_instance))->renderedCharts()[0]['name'];

            return ["{$folder}/{$chart}.values.yaml", "{$folder}/{$chart}.deployed.yaml"];
        })->all();

        expect(array_keys($files))->toEqualCanonicalizing([...$expected, 'manifest.json'])
            ->and($manifest)->toMatchArray(['core_version' => CoreVersion::current(), 'instances' => 2, 'failures' => []])
            ->and($manifest['files'])->toHaveCount(4);
    });

    it('has a manifest that says what each file is and its hash', function () {
        $app_instance = Fixtures::instance('wordpress');

        $this->artisan('backup:charts')->assertSuccessful();

        $files = backupChartsArchive();
        $entry = backupChartsManifest($files)['files'][0];

        expect($entry)->toMatchArray([
            'instance_id' => $app_instance->id,
            'organization' => $app_instance->organization->slug,
            'application' => 'wordpress',
            'version' => $app_instance->version->name,
        ])
            ->and($entry['kind'])->toBeIn(['values', 'deployed'])
            ->and($entry['sha256'])->toBe(hash('sha256', $files[$entry['path']]));
    });

    it('is what the Helm values page shows, both ways', function () {
        $app_instance = Fixtures::instance('nextcloud');

        $this->artisan('backup:charts')->assertSuccessful();

        $files = backupChartsArchive();
        $rendered = (new AppInstanceService($app_instance->fresh()))->renderedCharts()[0];
        $folder = "{$app_instance->organization->slug}/{$app_instance->name}/{$rendered['name']}";

        expect(Yaml::parse($files["{$folder}.values.yaml"]))->toEqual($rendered['values'])
            ->and(Yaml::parse($files["{$folder}.deployed.yaml"]))->toEqual($rendered['deployed']);
    });

    it('has in what is deployed what the chart does not build, the labels among it', function (string $slug, array $label_keys) {
        $app_instance = Fixtures::instance($slug);

        $this->artisan('backup:charts')->assertSuccessful();

        $files = backupChartsArchive();
        $folder = "{$app_instance->organization->slug}/{$app_instance->name}/".(new AppInstanceService($app_instance))->renderedCharts()[0]['name'];

        $values = Yaml::parse($files["{$folder}.values.yaml"]);
        $deployed = Yaml::parse($files["{$folder}.deployed.yaml"]);

        // Each chart takes the labels under keys of its own
        expect(array_keys($values))->not->toContain(...$label_keys)
            ->and(array_keys($deployed))->toContain(...$label_keys);
    })->with([
        'WordPress' => ['wordpress', ['commonLabels']],
        'Nextcloud' => ['nextcloud', ['deploymentLabels', 'podLabels']],
    ]);
});

describe('what it leaves alone', function () {
    it('changes nothing in the database', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $settings = AppInstance::find($app_instance->id)->settings;

        // Building the chart would give the release a name, and keep the storage it was sized with
        expect($settings)->not->toHaveKey('override');

        $this->artisan('backup:charts')->assertSuccessful();

        expect(AppInstance::find($app_instance->id)->settings)->toBe($settings);
    });

    it('does not leave in the model what building the chart wrote', function () {
        $app_instance = Fixtures::instance('wordpress');
        $before = $app_instance->getAttributes();

        (new AppInstanceService($app_instance))->renderedCharts();

        expect($app_instance->getAttributes())->toBe($before)
            ->and($app_instance->isDirty())->toBeFalse();
    });

    it('has none of the secrets of the apps in the archive', function () {
        $app_instance = Fixtures::instance('nextcloud');
        $secrets = [Application::instance($app_instance)->api_password(), $app_instance->dbPassword()];

        $this->artisan('backup:charts')->assertSuccessful();

        $archive = implode("\n", backupChartsArchive());

        expect($secrets[0])->not->toBeEmpty()
            ->and($archive)->toContain('••••••••');

        foreach (array_filter($secrets) as $secret) {
            expect($archive)->not->toContain($secret);
        }
    });
});

describe('which apps', function () {
    it('leaves out those that are deactivated and those with no web server', function () {
        $listed = Fixtures::instance('wordpress');
        $deactivated = Fixtures::instance('nextcloud');
        $deactivated->forceFill(['status' => 'deactivated'])->save();
        $no_server = Fixtures::instance('civicrm-standalone');
        $no_server->forceFill(['web_server_id' => null])->save();

        $this->artisan('backup:charts')->assertSuccessful();

        $manifest = backupChartsManifest(backupChartsArchive());

        expect($manifest['instances'])->toBe(1)
            ->and(collect($manifest['files'])->pluck('instance_id')->unique()->all())->toBe([$listed->id]);
    });

    it('writes nothing, and says so, when there are none', function () {
        $this->artisan('backup:charts')->assertSuccessful()->expectsOutputToContain('no app instances');

        expect(Storage::disk(config('filesystems.app_backup'))->files('chart-values'))->toBe([]);
    });

    it('goes on past an app that can not be rendered, and fails at the end, naming it in the manifest', function () {
        $listed = Fixtures::instance('wordpress');
        // A volume with no mount path: the generic chart refuses to build it
        $broken = Fixtures::instance('generic', ['persistence-enabled' => true, 'persistence-0-mountPath' => '']);

        $this->artisan('backup:charts')->assertFailed()->expectsOutputToContain('could not be rendered');

        $manifest = backupChartsManifest(backupChartsArchive());

        expect($manifest['instances'])->toBe(2)
            ->and($manifest['failures'])->toHaveCount(1)
            ->and($manifest['failures'][0])->toMatchArray(['instance_id' => $broken->id, 'application' => 'generic'])
            ->and($manifest['failures'][0]['message'])->toContain('needs a name and mountPath')
            ->and(collect($manifest['files'])->pluck('instance_id')->unique()->all())->toBe([$listed->id]);
    });
});
