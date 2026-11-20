<?php

namespace App\Providers;

use App\EmailForwarder;
use App\Ldap\LdapLegacyOrgMailSource;
use App\Ldap\LdapAliasDirectory;
use App\Ldap\LdapMailAddressRegistry;
use App\Ldap\LdapRecoveryEmailDirectory;
use App\OrgDomain;
use App\Services\Email\AliasDirectory;
use App\Services\Email\CompositeMailAddressRegistry;
use App\Services\Email\DatabaseMailAddressRegistry;
use App\Services\Email\LegacyOrgMailSource;
use App\Services\Email\MailAddressRegistry;
use App\Services\Email\RecoveryEmailDirectory;
use App\Services\EmailService;
use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use App\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Scoped: EmailService stores per-call DNS-check results (domain,
        // dkim/spf/dmarc flags) on the instance rather than returning them,
        // so a singleton risks one request reading state left behind by
        // another under FrankenPHP/Octane worker mode.
        $this->app->scoped('email', function ($app) {
            return new EmailService;
        });

        // Where a recovery email is mirrored to when LDAP is in use. Tests replace it with a fake.
        $this->app->bind(RecoveryEmailDirectory::class, LdapRecoveryEmailDirectory::class);

        // Where a mailbox's aliases are mirrored to when LDAP is in use. Tests replace it with a fake.
        $this->app->bind(AliasDirectory::class, LdapAliasDirectory::class);

        // Where the old orgMail values are read from, until they have all been migrated
        $this->app->bind(LegacyOrgMailSource::class, LdapLegacyOrgMailSource::class);

        // Who holds a mail address already: the mailbox table, and with LDAP the directory too
        $this->app->bind(MailAddressRegistry::class, function () {
            $registries = [new DatabaseMailAddressRegistry];

            if (LdapEmailAttributes::usingLdap()) {
                $registries[] = new LdapMailAddressRegistry;
            }

            return new CompositeMailAddressRegistry(...$registries);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Gate::define('view-emails', function (User $user) {
            $organization = $user->organization;
            $plan = Subscription::all();
            $domains = $organization->domains()->active()->emailEnabled()->emailActive()->count();

            return $organization->status !== 'deactivated'
                && (Organization::countEmail() > 0
                    || ($plan->domainsEnabled() && $plan->emailEnabled() && $domains > 0))
                ? Response::allow()
                : Response::deny(__('organization.email.denied.view'));
        });

        Gate::define('add-email-accounts', function (User $user) {
            $organization = $user->organization;
            $base_plan = Subscription::refresh()->base($organization);

            return $organization->status !== 'deactivated'
                && $base_plan->emailEnabled()
                && ! $base_plan->isMax('email')
                ? Response::allow()
                : Response::deny(__('organization.email.denied.create'));
        });

        Gate::define('add-user-email-account', function (User $user, $organization_user) {
            return $user->organization->status != 'deactivated'
                && Subscription::base()->emailEnabled()
                && AccountManager::users()->find($organization_user)->isUserAccessType('standard')
                ? Response::allow()
                : Response::deny();
        });

        Gate::define('add-user-email-account-to-domain', function (User $user, $organization_user, OrgDomain $domain) {
            return $user->organization->status !== 'deactivated'
                && $domain->organization_id === $user->organization->id
                && $domain->belongsToOrganization($user->organization)
                && Subscription::base()->emailEnabled()
                && AccountManager::users()->find($organization_user)->isUserAccessType('standard')
                ? Response::allow()
                : Response::deny(__('organization.user.denied.email_domain'));
        });

        Gate::define('activate-emails', function (User $user) {
            return $user->organization->status !== 'deactivated'
                && Subscription::emailEnabled()
                && $user->organization->primary_domain
                && $user->organization->primary_domain->type != 'base'
                && ! $user->organization->primary_domain->email_server()
                ? Response::allow()
                : Response::deny(__('organization.email.denied.activate'));
        });

        Gate::define('edit-email-settings', function (User $user, ?EmailForwarder $forwarder = null) {
            $organization = $user->organization;
            $domains = $organization->domains()->emailEnabled()->active()->get();

            return $organization->status !== 'deactivated'
                && Subscription::emailEnabled()
                && $domains->count() != 0
                && (! $forwarder || $forwarder->organization_id === $organization->id)
                ? Response::allow()
                : Response::deny(__('organization.email.denied.edit'));
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['email'];
    }
}
