<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\AppInstance;
use App\Organization;
use App\Support\Facades\Application;
use Illuminate\Support\Str;

class Chart
{
    public $name = '';

    public $namespace;

    public function __construct(
        public Organization $organization,
        public AppInstance $app_instance,
    ) {
        $this->name = $this->app_instance->name;
    }

    private ?ChartSecrets $secrets = null;

    public function namespace()
    {
        return $this->namespace ?? $this->organization->slug;
    }

    // Everything sensitive this chart needs goes in here rather than into the
    // values/env as plaintext. Memoized so values() and the driver that later
    // applies the Secret to the cluster see the same store.
    public function secrets(): ChartSecrets
    {
        return $this->secrets ??= new ChartSecrets($this->namespace(), $this->secretsName(), $this->secretsRelease());
    }

    // Whether the web server's driver creates this chart's Secret before the
    // workload starts. Only helm_k8s does; anything else has to keep using
    // plaintext values, or the secretKeyRef would point at a Secret that
    // never exists.
    protected function secretsDelivered(): bool
    {
        return $this->app_instance->web_server?->server?->interface === 'helm_k8s';
    }

    // A container env entry for a secret value: read from the chart's Secret
    // when the driver delivers it, plain {name, value} otherwise. The Secret
    // key defaults to the env name in kebab-case (NO_REPLY_PASSWORD ->
    // no-reply-password).
    public function secretEnv(string $name, string|int|float|null $value, ?string $key = null): array
    {
        if (! $this->secretsDelivered()) {
            return ['name' => $name, 'value' => $value];
        }

        $key ??= str_replace('_', '-', Str::lower($name));
        $this->secrets()->set($key, $value);

        return $this->secrets()->envVar($name, $key);
    }

    protected function secretsName(): string
    {
        return Str::slug("{$this->organization->slug}-{$this->name}-secrets");
    }

    // Release label on the Secret, so it can be found/cleaned up with its release.
    protected function secretsRelease(): ?string
    {
        return null;
    }

    public function extraEnv()
    {
        $name = $this->app_instance->application->slug;
        $env_vars = [];

        $app = Application::profile($name);
        foreach (Application::profile($name)->envs() as $env_class) {
            $env = new $env_class;
            $secret_names = $env->secretNames();
            foreach ($env->get($this->app_instance) as $name => $value) {
                $env_vars[] = in_array($name, $secret_names, true)
                    ? $this->secretEnv($name, $value)
                    : ['name' => $name, 'value' => $value];
            }
        }

        return array_merge($env_vars, $this->convertFeaturesToEnvVars());
    }

    public function convertFeaturesToEnvVars()
    {
        $app_instance_features = Application::instance($this->app_instance)->features();
        $app_features = Application::profile($this->app_instance->application->slug)->features();
        $app_features_keys = $app_features->keys()->all();

        $env_vars = [];
        foreach ($app_instance_features->all() as $name => $feature) {

            // Check if feature is registered
            if (in_array($name, $app_features_keys)) {
                $env_vars[] = [
                    'name' => $app_features[$name]->var_name,
                    'value' => $feature['status'] == 'enabled' ? '1' : '0',
                ];

                // If feature enabled, check for settings and include them in chart
                if ($feature['status'] == 'enabled') {
                    foreach ($feature['settings'] as $name => $setting) {
                        $env_vars[] = [
                            'name' => strtoupper($name),
                            'value' => $setting,
                        ];
                    }
                }
            }
        }

        return $env_vars;
    }

    public function sidecars()
    {
        $name = $this->app_instance->application->slug;
        $sidecars = [];

        $app = Application::profile($name)->sidecars();
        foreach ($app as $sidecar_class) {
            $sidecar = new $sidecar_class;
            if ($get_sidecar = $sidecar->get($this->app_instance)) {
                $sidecars[] = $sidecar->get($this->app_instance);
            }
        }

        return $sidecars;
    }

    // The names of any imagePullSecrets required to pull the application's container image
    public function imagePullSecrets(): array
    {
        $version = $this->app_instance->version;

        if ($version && $version->requiresPullSecret()) {
            return [$version->pullSecret->k8sSecretName()];
        }

        return [];
    }

    // The registry to pull the application's container image from
    public function imageRegistry(): ?string
    {
        $version = $this->app_instance->version;

        if ($version->pullSecret) {
            return $version->pullSecret->registry;
        }

        return $version->setting('image_registry');
    }

    // The fully-qualified image reference (registry/repository:tag) for the application's container image
    public function image(): string
    {
        $version = $this->app_instance->version;
        $repository = $version->setting('image_repo_name').':'.$version->name;

        return $this->imageRegistry() ? $this->imageRegistry().'/'.$repository : $repository;
    }
}
