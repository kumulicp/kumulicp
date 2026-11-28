<?php

use App\AppInstance;
use App\Application;
use App\AppVersion;
use App\Organization;
use App\Support\SettingsSnapshot;

// An instance whose settings are the given ones
function instanceWithSettings(?array $settings): AppInstance
{
    $application = Application::factory()->create();

    return AppInstance::factory()->create([
        'application_id' => $application->id,
        'version_id' => AppVersion::factory()->create(['application_id' => $application->id])->id,
        'organization_id' => Organization::factory()->create()->id,
        'settings' => $settings,
    ]);
}

beforeEach(function () {
    $this->app_instance = instanceWithSettings([
        'existing_claim' => 'old-claim',
        'empty' => null,
        'configurations' => ['persistence-accessMode' => 'ReadWriteOnce'],
        'other' => 'kept',
    ]);
});

it('writes each path and gives back what each held', function () {
    $before = SettingsSnapshot::apply($this->app_instance, [
        'existing_claim' => 'restored-data',
        'configurations.persistence-accessMode' => 'ReadWriteMany',
        'chart_storage' => 40,
    ]);

    $settings = $this->app_instance->fresh()->settings;
    expect($settings['existing_claim'])->toBe('restored-data')
        ->and($settings['configurations'])->toBe(['persistence-accessMode' => 'ReadWriteMany'])
        ->and($settings['chart_storage'])->toBe(40)
        ->and($before)->toBe([
            'existing_claim' => ['value' => 'old-claim'],
            'configurations.persistence-accessMode' => ['value' => 'ReadWriteOnce'],
            // Known not to have been there
            'chart_storage' => null,
        ]);
});

it('knows a place that held a null from a place that was not there', function () {
    $before = SettingsSnapshot::apply($this->app_instance, ['empty' => 'now', 'missing' => 'now']);

    expect($before['empty'])->toBe(['value' => null])
        ->and($before['missing'])->toBeNull();

    SettingsSnapshot::restore($this->app_instance->fresh(), $before);

    $settings = $this->app_instance->fresh()->settings;
    expect($settings)->toHaveKey('empty')
        ->and($settings['empty'])->toBeNull()
        ->and($settings)->not->toHaveKey('missing');
});

it('puts back exactly what was there, after it went through a task\'s saved values', function () {
    $original = $this->app_instance->settings;

    $before = SettingsSnapshot::apply($this->app_instance, ['existing_claim' => 'restored-data', 'chart_storage' => 50]);
    $before = json_decode(json_encode($before), true);

    SettingsSnapshot::restore($this->app_instance->fresh(), $before);

    expect($this->app_instance->fresh()->settings)->toBe($original);
});

it('leaves what it was not asked about alone', function () {
    SettingsSnapshot::apply($this->app_instance, ['chart_storage' => 50]);

    expect($this->app_instance->fresh()->settings)->toMatchArray(['other' => 'kept', 'existing_claim' => 'old-claim']);
});

it('works on an instance that has no settings yet', function () {
    $app_instance = instanceWithSettings(null);

    $before = SettingsSnapshot::apply($app_instance, ['chart_storage' => 50]);

    expect($app_instance->fresh()->settings)->toBe(['chart_storage' => 50]);

    SettingsSnapshot::restore($app_instance->fresh(), $before);

    expect($app_instance->fresh()->settings)->toBe([]);
});
