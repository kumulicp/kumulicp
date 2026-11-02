<?php

use App\Contracts\Tickets\TicketIntegrationDriver;
use App\Services\Tickets\Integrations\Capability;
use App\Services\Tickets\Integrations\IntegrationConfig;
use App\Services\Tickets\Integrations\TestResult;
use App\Services\Tickets\Integrations\TicketIntegrationRegistry;

/**
 * What a module would ship to add its own kind of integration.
 */
class ExampleModuleDriver implements TicketIntegrationDriver
{
    public static function key(): string
    {
        return 'example_module';
    }

    public function label(): string
    {
        return 'Example';
    }

    public function configSchema(): array
    {
        return [['key' => 'endpoint', 'type' => 'url', 'label' => 'Endpoint', 'required' => true]];
    }

    public function capabilities(): array
    {
        return [Capability::EVENT_PUSH];
    }

    public function testConnection(IntegrationConfig $config): TestResult
    {
        return TestResult::ok('fine');
    }
}

it('comes with webhook, GitHub and GitLab installed', function () {
    expect(array_keys(app(TicketIntegrationRegistry::class)->all()))->toContain('generic_webhook', 'github', 'gitlab');
});

it('lets a module add a driver without touching the core', function () {
    $registry = app(TicketIntegrationRegistry::class);

    $registry->register(ExampleModuleDriver::class);

    expect($registry->has('example_module'))->toBeTrue()
        ->and($registry->driver('example_module'))->toBeInstanceOf(ExampleModuleDriver::class)
        ->and($registry->driver('example_module')->configSchema()[0]['key'])->toBe('endpoint');
});

it('shares one registry for the life of the application, so what a module registers at boot survives', function () {
    app(TicketIntegrationRegistry::class)->register(ExampleModuleDriver::class);

    expect(app(TicketIntegrationRegistry::class))->toBe(app(TicketIntegrationRegistry::class))
        ->and(app(TicketIntegrationRegistry::class)->has('example_module'))->toBeTrue();
});

it('hands out a fresh driver each time, so no state can leak between uses', function () {
    $registry = app(TicketIntegrationRegistry::class);

    expect($registry->driver('github'))->not->toBe($registry->driver('github'));
});

it('answers nothing for a driver that is not installed', function () {
    $registry = app(TicketIntegrationRegistry::class);

    expect($registry->has('missing'))->toBeFalse()->and($registry->driver('missing'))->toBeNull();
});

it('refuses to register something that is not a driver', function () {
    expect(fn () => app(TicketIntegrationRegistry::class)->register(stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('describes every built-in driver well enough to draw its form', function (string $key) {
    $driver = app(TicketIntegrationRegistry::class)->driver($key);

    expect($driver->label())->not->toBe('')->and($driver->configSchema())->not->toBeEmpty();

    foreach ($driver->configSchema() as $field) {
        expect($field)->toHaveKeys(['key', 'type', 'label'])
            ->and($field['type'])->toBeIn(['text', 'url', 'secret', 'select', 'number', 'toggle']);
    }
})->with(['generic_webhook', 'github', 'gitlab']);
