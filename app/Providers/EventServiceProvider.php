<?php

namespace App\Providers;

use App\Events\AppInstanceSubscriptionChanged;
use App\Events\Apps\AppInstanceDomainChanged;
use App\Events\Apps\AppInstanceUpdated;
use App\Events\Apps\ApplicationActivated;
use App\Events\Apps\ApplicationActivating;
use App\Events\Apps\ApplicationPreActivation;
use App\Events\Domains\DomainDeleted;
use App\Events\OrganizationCreated;
use App\Events\OrganizationRegistered;
use App\Events\SubscriptionUpdated;
use App\Events\TestEvent;
use App\Events\Users\DeletingUser;
use App\Events\Users\UserCreated;
use App\Events\Users\UserDeleted;
use App\Events\Users\UserPermissionsUpdated;
use App\Events\Users\UserStorageUpdated;
use App\Events\Users\UserUpdated;
use App\Integrations\Applications\Nextcloud\Actions\NextcloudUpdateGroupFolderStorageQuota;
use App\Integrations\ServerManagers\Rancher\Listeners\UpdateIngressMiddleware;
use App\Integrations\SSO\Authentik\Listeners\SyncLDAP;
use App\Integrations\SSO\Authentik\Listeners\UpdateAppInfo;
use App\Listeners\NotifyCpAdminOfNewOrganization;
use App\Jobs\Users\UpdateUserStorage;
use App\Listeners\UpdateAppLdapGroups;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Event::listen(UserPermissionsUpdated::class, SyncLDAP::class);
        Event::listen(DeletingUser::class, SyncLDAP::class);
        Event::listen(UserCreated::class, SyncLDAP::class);
        Event::listen(UserUpdated::class, SyncLDAP::class);
        Event::listen(UserDeleted::class, SyncLDAP::class);
        Event::listen(UserStorageUpdated::class, UpdateUserStorage::class);

        Event::listen(AppInstanceSubscriptionChanged::class, NextcloudUpdateGroupFolderStorageQuota::class);
        Event::listen(AppInstanceSubscriptionChanged::class, UpdateAppLdapGroups::class);

        Event::listen(AppInstanceDomainChanged::class, UpdateAppInfo::class);

        Event::listen(DomainDeleted::class, UpdateIngressMiddleware::class);

        Event::listen(OrganizationCreated::class, NotifyCpAdminOfNewOrganization::class);
    }
}
