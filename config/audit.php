<?php

use App\AdditionalStorage;
use App\Announcement;
use App\AppInstance;
use App\Application;
use App\AppPlan;
use App\AppRole;
use App\AppVersion;
use App\BackupSchedule;
use App\EmailForwarder;
use App\Group;
use App\GroupMember;
use App\Organization;
use App\OrgBackup;
use App\OrgDomain;
use App\OrgServer;
use App\OrgSubdomain;
use App\Plan;
use App\RecurringBackup;
use App\RepoSecret;
use App\Server;
use App\SsoProvider;
use App\SuborgUser;
use App\Tld;
use App\User;
use App\UserSsoAccount;

return [
    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Audit logging is switched on and off from Admin > Settings (the
    | "audit_logging_enabled" server setting) and is off by default. These
    | options control how entries are kept once it is on.
    |
    */

    // Log channel every audit entry is also written to, as one JSON line.
    // Defaults to the "audit" channel (JSON on stderr, for Loki / Alloy) when
    // the stderr_json channel is part of LOG_STACK, otherwise nothing is streamed.
    'channel' => env('AUDIT_LOG_CHANNEL', in_array('stderr_json', array_map('trim', explode(',', (string) env('LOG_STACK', ''))), true) ? 'audit' : 'null'),

    // Days to keep rows in the audit_logs table. Empty keeps them forever.
    'retention_days' => env('AUDIT_RETENTION_DAYS') !== null && env('AUDIT_RETENTION_DAYS') !== '' ? (int) env('AUDIT_RETENTION_DAYS') : null,

    // Models whose create / update / delete are recorded automatically.
    'models' => [
        User::class,
        Organization::class,
        SuborgUser::class,
        Group::class,
        GroupMember::class,
        UserSsoAccount::class,
        SsoProvider::class,
        Plan::class,
        AppPlan::class,
        Application::class,
        AppVersion::class,
        AppRole::class,
        AppInstance::class,
        OrgDomain::class,
        OrgSubdomain::class,
        OrgServer::class,
        OrgBackup::class,
        Server::class,
        RepoSecret::class,
        RecurringBackup::class,
        BackupSchedule::class,
        Tld::class,
        Announcement::class,
        AdditionalStorage::class,
        EmailForwarder::class,
    ],

    // Request paths (not the HTTP verb) that change state even though they are GET routes.
    'state_changing_get_pattern' => '#/(remove|delete|reactivate|deactivate|activate|restart|archive|restore|set_default|add|run|upgrade|install|enable|disable|run_schedule|restart_queue|download)(/|$)#',
];
