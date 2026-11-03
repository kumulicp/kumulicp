<?php

namespace Tests\Support\ServerManagers;

use App\AppInstance;
use App\Contracts\OrganizationInterface;
use App\Contracts\ServerManager\AppInterface;
use App\Contracts\ServerManager\JobInterface;
use App\Integrations\ServerManagers\Rancher\Charts\Job\JobChart;
use App\OrgServer;

/**
 * In-memory stand-in for RancherWebInterface.
 *
 * Registered via ServerInterfaceService::register('web', 'rancher', self::class)
 * so that any action calling $app_instance->connect('web') receives this fake
 * instead of a real Rancher connection. No HTTP calls, no sleeps.
 */
class FakeServerManager implements AppInterface, JobInterface, OrganizationInterface
{
    private static array $deleted_instances = [];

    private static array $pending_instances = [];

    private static array $inactive_instances = [];

    private static array $missing_instances = [];

    private static bool $organization_missing = false;

    public static int $recover_stuck_release_calls = 0;

    public static function reset(): void
    {
        self::$deleted_instances = [];
        self::$pending_instances = [];
        self::$inactive_instances = [];
        self::$missing_instances = [];
        self::$organization_missing = false;
        self::$recover_stuck_release_calls = 0;
    }

    public static function markPending(int $app_instance_id): void
    {
        self::$pending_instances[] = $app_instance_id;
    }

    public static function clearPending(int $app_instance_id): void
    {
        self::$pending_instances = array_diff(self::$pending_instances, [$app_instance_id]);
    }

    // Simulates a release that's been scaled/stopped without being deleted
    // -- e.g. what ApplicationDeactivate::complete() polls for (isActive()
    // going false) as distinct from ApplicationDelete's "release is gone".
    public static function markInactive(int $app_instance_id): void
    {
        self::$inactive_instances[] = $app_instance_id;
    }

    public static function clearInactive(int $app_instance_id): void
    {
        self::$inactive_instances = array_diff(self::$inactive_instances, [$app_instance_id]);
    }

    // Simulates an app that isn't on the server at all (exists() is false)
    public static function markMissing(int $app_instance_id): void
    {
        self::$missing_instances[] = $app_instance_id;
    }

    // Simulates an organization that has to be created on the server first;
    // addOrganization() then creates it
    public static function missingOrganization(bool $missing = true): void
    {
        self::$organization_missing = $missing;
    }

    public function __construct(
        private OrgServer $server,
        private ?AppInstance $app_instance = null,
    ) {}

    public function exists(): bool
    {
        return ! ($this->app_instance && in_array($this->app_instance->id, self::$missing_instances));
    }

    public function notFoundMessage(): string
    {
        return 'Please review the error in the fake server manager.';
    }

    public function get(): mixed
    {
        return null;
    }

    public function isActive(): bool
    {
        return $this->checkStatus()['active'];
    }

    public function checkStatus(): array
    {
        if ($this->app_instance && in_array($this->app_instance->id, self::$deleted_instances)) {
            return ['active' => false, 'pending' => false, 'message' => 'not found'];
        }

        if ($this->app_instance && in_array($this->app_instance->id, self::$pending_instances)) {
            return ['active' => false, 'pending' => true, 'message' => 'pending-upgrade'];
        }

        if ($this->app_instance && in_array($this->app_instance->id, self::$inactive_instances)) {
            return ['active' => false, 'pending' => false, 'message' => 'stopped'];
        }

        return ['active' => true, 'pending' => false, 'message' => 'deployed'];
    }

    public function recoverStuckRelease(): void
    {
        self::$recover_stuck_release_calls++;

        if ($this->app_instance) {
            self::$pending_instances = array_diff(self::$pending_instances, [$this->app_instance->id]);
        }
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
        if ($this->app_instance) {
            self::$deleted_instances[] = $this->app_instance->id;
        }

        return true;
    }

    public function existsOrganization(): bool
    {
        return ! self::$organization_missing;
    }

    public function organization(): void {}

    public function addOrganization(): bool
    {
        self::$organization_missing = false;

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

    public function runJob(JobChart $job_chart, ?string $namespace = null): array
    {
        return ['status' => 'success', 'response' => ['metadata' => ['name' => 'fake-job-'.uniqid()]]];
    }

    public function jobStatus(string $job_id, ?string $namespace = null): string
    {
        return 'success';
    }

    public function jobLogs(string $job_id, ?string $namespace = null): ?string
    {
        return null;
    }
}
