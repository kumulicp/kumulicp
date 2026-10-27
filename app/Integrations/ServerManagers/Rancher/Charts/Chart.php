<?php

namespace App\Integrations\ServerManagers\Rancher\Charts;

use App\AppInstance;
use App\Integrations\Applications\AppFeature;
use App\Organization;
use App\Support\Facades\Application;
use App\Support\SecretValue;
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

    // Name shared by the redirect ingress and middleware: <app-name>-<instance-id>-redirect
    protected function redirectName(): string
    {
        return $this->app_instance->name.'-redirect';
    }

    private ?ChartSecrets $secrets = null;

    public function namespace()
    {
        return $this->namespace ?? $this->organization->slug;
    }

    // The kind of workload this chart creates (the kumulicp.io/type label), so
    // NetworkPolicies can select it. Job charts override this.
    public string $type = 'app';

    // Labels stamped on everything this chart creates so the cluster resources
    // can be traced back to the app and the specific app instance they came from.
    public function labels(): array
    {
        return [
            'kumulicp.io/app' => $this->labelValue($this->app_instance->application->slug),
            'kumulicp.io/instance' => $this->labelValue($this->app_instance->name),
            'kumulicp.io/type' => $this->type,
        ];
    }

    // Kubernetes label values: max 63 chars of [A-Za-z0-9_.-], starting and ending alphanumeric
    private function labelValue(string $value): string
    {
        $value = Str::limit(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value), 63, '');

        return trim($value, '-_.');
    }

    // Everything sensitive this chart needs goes in here rather than into the
    // values/env as plaintext. Memoized so values() and the driver that later
    // applies the Secret to the cluster see the same store.
    public function secrets(): ChartSecrets
    {
        return $this->secrets ??= new ChartSecrets($this->namespace(), $this->secretsName(), $this->secretsRelease(), $this->labels());
    }

    // Whether the web server's driver creates this chart's Secret before the
    // workload starts. Only the kubernetes driver does; anything else has to keep using
    // plaintext values, or the secretKeyRef would point at a Secret that
    // never exists.
    protected function secretsDelivered(): bool
    {
        return $this->app_instance->web_server?->server?->interface === 'kubernetes';
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

        return array_merge($env_vars, $this->convertFeaturesToEnvVars(), $this->planSecretEnv());
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
                    $secret_settings = $app_features[$name] instanceof AppFeature ? $app_features[$name]->secretSettings() : [];

                    foreach ($feature['settings'] as $setting_name => $setting) {
                        $env_vars[] = in_array($setting_name, $secret_settings, true)
                            ? $this->secretEnv(strtoupper($setting_name), SecretValue::decrypt($setting))
                            : ['name' => strtoupper($setting_name), 'value' => $setting];
                    }
                }
            }
        }

        return $env_vars;
    }

    // The plan's custom secrets (set on the plan's Secrets page), one env var each
    public function planSecretEnv(): array
    {
        $plan = $this->app_instance->plan;

        if (! $plan) {
            return [];
        }

        $env_vars = [];
        foreach (Application::plan($plan)->secrets()->values() as $name => $value) {
            $env_vars[] = $this->secretEnv($name, $value);
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

        if ($version && $version->requiresImageRegistryAuth()) {
            return [$version->imageRegistry->k8sSecretName()];
        }

        return [];
    }

    // The registry to pull the application's container image from
    public function imageRegistry(): string
    {
        $version = $this->app_instance->version;

        // Never return null/empty: a null 'image.registry' value makes Helm drop the
        // chart's default, and the unquoted image line then renders as "%!s(<nil>)/..."
        // which fails YAML parsing ("found character that cannot start any token").
        return $version->imageRegistry?->registry ?: $version->setting('image_registry') ?: 'docker.io';
    }

    // The fully-qualified image reference (registry/repository:tag) for the application's container image
    public function image(): string
    {
        $version = $this->app_instance->version;
        $repository = $version->setting('image_repo_name').':'.$version->name;

        return $this->imageRegistry() ? $this->imageRegistry().'/'.$repository : $repository;
    }
}
