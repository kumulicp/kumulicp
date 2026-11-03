<?php

use App\Providers\ActionServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\EmailServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\TicketIntegrationServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    EventServiceProvider::class,
    ActionServiceProvider::class,
    DomainServiceProvider::class,
    EmailServiceProvider::class,
    BillingServiceProvider::class,
    TicketIntegrationServiceProvider::class,
];
