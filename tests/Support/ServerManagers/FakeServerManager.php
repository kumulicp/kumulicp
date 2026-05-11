<?php

namespace Tests\Support\ServerManagers;

use App\AppInstance;
use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\AppInterface;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\OrgServer;

/**
 * In-memory stand-in for RancherWebInterface.
 *
 * Registered via ServerInterfaceService::register('web', 'rancher', self::class)
 * so that any action calling $app_instance->connect('web') receives this fake
 * instead of a real Rancher connection. No HTTP calls, no sleeps.
 */
class FakeServerManager implements AppInterface, OrganizationInterface
{
    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {}

    public function exists(): bool
    {
        return true;
    }

    public function get(): mixed
    {
        return null;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function add(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }

    public function existsOrganization(): bool
    {
        return true;
    }

    public function organization(): void {}

    public function addOrganization(): bool
    {
        return true;
    }

    public function updateOrganization(): ?bool
    {
        return null;
    }

    public function deleteOrganization(): bool
    {
        return true;
    }

    public function updateRedirectDomains(): void {}

    public function runJob(JobChart $job_chart): array
    {
        return ['metadata' => ['name' => 'fake-job-'.uniqid()]];
    }

    public function jobStatus(string $job_id): string
    {
        return 'Succeeded';
    }
}
