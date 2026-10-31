<?php

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
        App\User::class,
        App\Organization::class,
        App\SuborgUser::class,
        App\Group::class,
        App\GroupMember::class,
        App\UserSsoAccount::class,
        App\SsoProvider::class,
        App\Plan::class,
        App\AppPlan::class,
        App\Application::class,
        App\AppVersion::class,
        App\AppRole::class,
        App\AppInstance::class,
        App\OrgDomain::class,
        App\OrgSubdomain::class,
        App\OrgServer::class,
        App\OrgBackup::class,
        App\Server::class,
        App\RepoSecret::class,
        App\RecurringBackup::class,
        App\BackupSchedule::class,
        App\Tld::class,
        App\Announcement::class,
        App\AdditionalStorage::class,
        App\EmailForwarder::class,
    ],

    // Request paths (not the HTTP verb) that change state even though they are GET routes.
    'state_changing_get_pattern' => '#/(remove|delete|reactivate|deactivate|activate|restart|archive|restore|set_default|add|run|upgrade|install|enable|disable|run_schedule|restart_queue|download)(/|$)#',
];
