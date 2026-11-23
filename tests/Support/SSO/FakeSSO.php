<?php

namespace Tests\Support\SSO;

use App\AppInstance;
use App\Exceptions\Tasks\PermanentFailure;
use App\OrgServer;

/**
 * In-memory stand-in for AuthentikSSOInterface.
 *
 * Registered via ServerInterfaceService::register('sso', 'authentik', self::class)
 * so that any action calling $app_instance->connect('sso') receives this fake
 * instead of a real Authentik connection. No HTTP calls.
 */
class FakeSSO
{
    /**
     * What it was asked to do, in order: [method, the OrgServer's id, the app's `sso` setting at the time].
     *
     * @var array<int, array{string, int, mixed}>
     */
    public static array $calls = [];

    // Makes add() fail for good (no retries) with this message
    public static ?string $failure = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$failure = null;
    }

    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {}

    public function exists(): bool
    {
        return true;
    }

    public function get(): void {}

    public function isActive(): void {}

    private function record(string $method): void
    {
        self::$calls[] = [$method, $this->server->id, $this->app_instance?->setting('sso')];
    }

    public function add(): array
    {
        $this->record('add');

        if (self::$failure) {
            throw new PermanentFailure(self::$failure);
        }

        return [
            'pk' => 'fake-app-'.uniqid(),
            'name' => $this->app_instance?->name ?? 'fake-app',
            'slug' => $this->app_instance?->name ?? 'fake-app',
        ];
    }

    public function update(): array
    {
        $this->record('update');

        return [
            'pk' => 'fake-app-'.uniqid(),
            'name' => $this->app_instance?->name ?? 'fake-app',
            'slug' => $this->app_instance?->name ?? 'fake-app',
        ];
    }

    public function delete(): bool
    {
        $this->record('delete');

        return true;
    }

    public function existsOrganization(): bool
    {
        return true;
    }
}
