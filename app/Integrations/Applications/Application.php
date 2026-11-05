<?php

namespace App\Integrations\Applications;

use App\AppInstance;
use App\Exceptions\AppUnavailableException;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Integration;
use App\Services\AppInstance\AvailabilityService;
use App\Support\Facades\Application as ApplicationFacade;
use Illuminate\Http\Client\ConnectionException;

class Application extends Integration
{
    public $action_description = '';

    public $action = '';

    // Whether the app was marked unavailable when the current request started
    private bool $was_unavailable = false;

    public function __construct(
        public AppInstance $app_instance
    ) {
        $organization = $app_instance->organization;

        parent::__construct($organization);
    }

    public function baseURI()
    {
        return $this->app_instance->address();
    }

    public function support_user()
    {
        $app = ApplicationFacade::instance($this->app_instance);

        return $app->configuration('username') ?? 'support';
    }

    public function setAppInstance($app_instance)
    {
        $this->app_instance = $app_instance;
        $this->organization = $app_instance->organization;

        return $this;
    }

    public function appName()
    {
        $class = explode('\\', get_called_class());

        return end($class);
    }

    protected function useDevIngressResolve(): bool
    {
        return true;
    }

    // A confirmed-unavailable app gets no API calls until a recheck finds it
    // answering. Read from the database, not from $this->app_instance: that
    // model may have been loaded before another process marked the app.
    protected function beforeRequest(): void
    {
        $current = AppInstance::whereKey($this->app_instance->getKey())->first(['id', 'status', 'settings']);

        $this->was_unavailable = (bool) $current?->isUnavailable();

        if ($current?->isApiBlocked()) {
            throw new AppUnavailableException(
                "{$this->app_instance->label} is unavailable. API calls to it are paused until it responds again."
            );
        }
    }

    protected function requestFailed(string $reason): void
    {
        try {
            app(AvailabilityService::class)->recordFailure($this->app_instance, $reason);
        } catch (\Throwable $e) {
            // Keeping track of availability must not replace the failure
            // that is already being reported
            report($e);
        }
    }

    protected function requestSucceeded(): void
    {
        if (! $this->was_unavailable) {
            return;
        }

        $this->was_unavailable = false;

        try {
            app(AvailabilityService::class)->recordSuccess($this->app_instance);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // The app can't be reached; a task running this call tries it again later
    protected function connectionError(ConnectionException $exception, string $url): \Throwable
    {
        return new ConnectionFailedException(
            "{$url} could not be reached - {$this->name}: {$exception->getMessage()}",
            0,
            $exception,
        );
    }
}
