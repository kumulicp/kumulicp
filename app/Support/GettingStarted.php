<?php

namespace App\Support;

use App\Application;
use App\Organization;
use App\Server;
use App\SsoProvider;
use App\Support\Facades\Settings;

/**
 * What an admin of a single-organization install still has to do before the
 * control panel is useful: add a server, get an app ready, give it a domain to
 * live on, and install it. Single sign-on is a suggestion, not a requirement.
 *
 * Each step is an array of: key, done, optional, label, hint and url.
 */
class GettingStarted
{
    public function __construct(private Organization $organization) {}

    /**
     * @return list<array{key: string, done: bool, optional: bool, label: string, hint: string, url: string}>
     */
    public function steps(): array
    {
        return [
            $this->step('servers', $this->hasServer(), false, '/admin/server/servers'),
            $this->step('app_ready', Application::where('enabled', 1)->exists(), false, '/admin/apps'),
            $this->step('domain', $this->hasDomain(), false, '/settings/domains'),
            $this->step('app_installed', $this->organization->app_instances()->exists(), false, '/discover'),
            $this->step('sso', $this->hasSignOn(), true, '/admin/settings/sso-providers'),
        ];
    }

    /**
     * Whether everything that is required has been done. Suggestions don't count.
     */
    public function isComplete(): bool
    {
        return collect($this->steps())->every(fn (array $step) => $step['done'] || $step['optional']);
    }

    private function hasServer(): bool
    {
        return Server::where('default_web_server', 1)->exists();
    }

    // Apps live under the system's base domain unless the organization has a domain of its own
    private function hasDomain(): bool
    {
        return (bool) Settings::get('base_domain') || count($this->organization->main_domains()) > 0;
    }

    private function hasSignOn(): bool
    {
        return config('account_manager.driver') === 'ldap' || SsoProvider::where('enabled', true)->exists();
    }

    /**
     * @return array{key: string, done: bool, optional: bool, label: string, hint: string, url: string}
     */
    private function step(string $key, bool $done, bool $optional, string $url): array
    {
        return [
            'key' => $key,
            'done' => $done,
            'optional' => $optional,
            'label' => __("organization.getting_started.{$key}.label"),
            'hint' => __("organization.getting_started.{$key}.hint"),
            'url' => $url,
        ];
    }
}
