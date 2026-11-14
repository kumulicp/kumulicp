<?php

namespace App\Support;

use App\Actions\Domains\UpdateDnsRecords;
use App\AppInstance;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Rules\OrgSubdomainAvailable;
use App\Support\Facades\Action;
use App\Support\Facades\Application;
use App\Support\Facades\Domain;
use Illuminate\Validation\Rule;

/**
 * Changing the domain an app instance is reached at, as an admin, from its
 * admin edit page. Unlike the organization's own page, nothing about the app limits
 * which domains an admin can pick: an app that doesn't allow its domain to be
 * edited only gets a warning.
 */
class AdminAppDomain
{
    /**
     * Whose domains the app can be reached at. A shared app belongs to the
     * shared organization, which has none of its own.
     */
    public static function owner(AppInstance $app): Organization
    {
        return $app->isSharedApp()
            ? Organization::where('type', 'superaccount')->firstOrFail()
            : $app->organization;
    }

    /**
     * Whether the app is running, so that changing its domain changes the
     * running app. A shared app that is waiting to be set up, or is managed
     * externally, has nothing deployed.
     */
    public static function isDeployed(AppInstance $app): bool
    {
        return ! $app->isSharedApp() || SharedAppContext::state($app) === SharedAppContext::ACTIVE;
    }

    /**
     * @return array{domains: array<int, array<string, mixed>>, parent_domains: array<int, array<string, mixed>>}
     */
    public static function options(AppInstance $app): array
    {
        $owner = self::owner($app);

        $domains = $owner->subdomains()
            ->where(fn ($query) => $query->whereIn('app_instance_id', [$app->id, 0])->orWhereNull('app_instance_id'))
            ->get();

        return [
            'domains' => $domains->map(fn (OrgSubdomain $domain) => [
                'id' => $domain->id,
                'name' => $domain->name,
            ])->push([
                'name' => $app->base_domain(),
                'id' => 0,
            ])->push([
                'name' => 'Add New Subdomain',
                'id' => 'new',
            ])->all(),
            'parent_domains' => $owner->domains->map(fn (OrgDomain $domain) => [
                'id' => $domain->id,
                'name' => $domain->name,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(AppInstance $app): array
    {
        $owner = self::owner($app);

        return [
            'domain' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($app, $owner) {
                    if ($value === 0 || $value === 'new') {
                        return;
                    }

                    $domain = OrgSubdomain::find($value);

                    if (! $domain || $domain->organization_id !== $owner->id || ! in_array($domain->app_instance_id, [$app->id, 0, null])) {
                        $fail(__('organization.domain.denied.exists'));
                    }
                },
            ],
            'parent_domain' => ['nullable', 'required_if:domain,new', 'numeric', Rule::exists('org_domains', 'id')->where('organization_id', $owner->id)],
            'subdomain' => ['nullable', 'required_if:domain,new', 'string', new OrgSubdomainAvailable($app)],
        ];
    }

    /**
     * Points the app at the domain that was validated with rules().
     *
     * @param  array<string, mixed>  $validated
     */
    public static function apply(AppInstance $app, array $validated): void
    {
        $current = $app->primary_domain;
        $new = null;

        // Added to custom domains
        if ($validated['domain'] === 'new') {
            $new = Domain::addSubdomain($validated['subdomain'], OrgDomain::findOrFail($validated['parent_domain']), $app);
        } elseif ($validated['domain'] !== 0) {
            // Can find directly since it should already be validated
            $new = OrgSubdomain::find($validated['domain']);
        }

        if (! self::isDeployed($app)) {
            // Nothing is running to move, and it points DNS at its domain when it is activated
            if ($new) {
                $new->app_instance_id = $app->id;
                $new->save();
            }

            $app->primary_domain_id = $new?->id;
            $app->save();

            return;
        }

        // Checks the domain points at the app's server, then updates the running app
        Application::instance($app)->updatePrimaryDomain($new);

        if ($current && $current->domain->type === 'managed') {
            Action::execute(new UpdateDnsRecords($app->organization, $current->domain));
        } elseif ($new && $new->domain->type === 'managed') {
            Action::execute(new UpdateDnsRecords($app->organization, $new->domain));
        }
    }
}
