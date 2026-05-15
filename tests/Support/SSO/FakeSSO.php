<?php

namespace Tests\Support\SSO;

use App\AppInstance;
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

    public function add(): array
    {
        return [
            'pk' => 'fake-app-'.uniqid(),
            'name' => $this->app_instance?->name ?? 'fake-app',
            'slug' => $this->app_instance?->name ?? 'fake-app',
        ];
    }

    public function update(): array
    {
        return $this->add();
    }

    public function delete(): bool
    {
        return true;
    }

    public function existsOrganization(): bool
    {
        return true;
    }
}
