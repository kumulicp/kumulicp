<?php

namespace App\Providers;

use App\Events\AppInstanceSubscriptionChanged;
use App\Events\Apps\AppInstanceDomainChanged;
use App\Events\Apps\AppInstanceUpdated;
use App\Events\Apps\ApplicationActivated;
use App\Events\Apps\ApplicationActivating;
use App\Events\Apps\ApplicationPreActivation;
use App\Events\Domains\DomainDeleted;
use App\Events\OrganizationRegistered;
use App\Events\SubscriptionUpdated;
use App\Events\TestEvent;
use App\Events\Users\DeletingUser;
use App\Events\Users\UserCreated;
use App\Events\Users\UserDeleted;
use App\Events\Users\UserPermissionsUpdated;
use App\Events\Users\UserUpdated;
use App\Integrations\Applications\Nextcloud\Actions\NextcloudUpdateGroupFolderStorageQuota;
use App\Integrations\ServerManagers\Rancher\Listeners\UpdateIngressMiddleware;
use App\Integrations\SSO\Authentik\Listeners\SyncLDAP;
use App\Integrations\SSO\Authentik\Listeners\UpdateAppInfo;
use App\Listeners\UpdateAppLdapGroups;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        ApplicationPreActivation::class => [
            //
        ],
        ApplicationActivating::class => [
            //
        ],
        ApplicationActivated::class => [
            //
        ],
        AppInstanceUpdated::class => [
            //
        ],
        UserPermissionsUpdated::class => [
            SyncLDAP::class,
        ],
        DeletingUser::class => [
            SyncLDAP::class,
        ],
        UserCreated::class => [
            SyncLDAP::class,
        ],
        UserUpdated::class => [
            SyncLDAP::class,
        ],
        UserDeleted::class => [
            SyncLDAP::class,
        ],
        OrganizationRegistered::class => [
        ],
        AppInstanceSubscriptionChanged::class => [
            NextcloudUpdateGroupFolderStorageQuota::class,
            UpdateAppLdapGroups::class,
        ],
        AppInstanceDomainChanged::class => [
            UpdateAppInfo::class,
        ],
        DomainDeleted::class => [
            UpdateIngressMiddleware::class,
        ],
        SubscriptionUpdated::class => [
            //
        ],
        TestEvent::class => [
            //
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        //
    }
}
