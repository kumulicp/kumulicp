<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->cleanupOrphanedNullableRefs();
        $this->addIndexes();
        $this->addForeignKeys();
    }

    // -------------------------------------------------------------------------
    // Step 1 — nullify any nullable FK columns that point at non-existent rows.
    // This lets nullOnDelete FKs be added even on databases with dirty data.
    // NOT-NULL FK columns are left for tryAddForeign() to handle individually.
    // -------------------------------------------------------------------------
    private function cleanupOrphanedNullableRefs(): void
    {
        $pairs = [
            // [child_table, child_column, parent_table]
            ['organizations', 'parent_organization_id', 'organizations'],
            ['organizations', 'plan_id',                'plans'],
            ['organizations', 'primary_contact_id',     'users'],
            ['organizations', 'account_test_id',        'account_tests'],
            ['app_instances', 'plan_id',                'app_plans'],
            ['app_instances', 'database_server_id',     'org_servers'],
            ['app_instances', 'sso_server_id',          'org_servers'],
            ['app_instances', 'parent_id',              'app_instances'],
            ['tasks',         'application_id',         'applications'],
            ['tasks',         'version_id',             'app_versions'],
            ['tasks',         'app_instance_id',        'app_instances'],
            ['app_versions',  'announcement_id',        'announcements'],
            ['plans',         'email_server_id',        'servers'],
            ['additional_storage', 'app_instance_id',  'app_instances'],
            ['org_backups',   'scheduled_backup_id',    'backup_schedules'],
            ['org_backups',   'app_instance_id',        'app_instances'],
            ['org_backups',   'org_server_id',          'org_servers'],
            ['backup_schedules', 'recurring_backup_id', 'recurring_backups'],
            ['recurring_backups', 'organization_id',    'organizations'],
            ['recurring_backups', 'application_id',     'applications'],
            ['org_domains',   'app_instance_id',        'app_instances'],
            ['org_domains',   'parent_domain_id',       'org_domains'],
            ['org_domains',   'tld_id',                 'tlds'],
            ['app_plans',     'web_server_id',          'servers'],
            ['app_plans',     'database_server_id',     'servers'],
            ['app_plans',     'sso_server_id',          'servers'],
            ['app_plans',     'shared_app_id',          'app_instances'],
            ['servers',       'default_backup_server_id', 'servers'],
            ['org_servers',   'backup_server_id',       'org_servers'],
            ['org_subdomains', 'app_instance_id',       'app_instances'],
            ['org_subdomains', 'parent_domain_id',      'org_domains'],
            ['app_instances', 'primary_domain_id',      'org_domains'],
        ];

        foreach ($pairs as [$child, $column, $parent]) {
            $count = DB::table($child)
                ->whereNotNull($column)
                ->whereNotIn($column, DB::table($parent)->pluck('id'))
                ->update([$column => null]);

            if ($count > 0) {
                Log::warning("FK pre-cleanup: set {$count} orphaned {$child}.{$column} values to NULL");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 2 — add indexes on every FK column (and high-value status columns).
    // Indexes are safe on any data so no error handling is needed.
    // -------------------------------------------------------------------------
    private function addIndexes(): void
    {
        $indexMap = [
            'users'              => ['organization_id'],
            'organizations'      => ['parent_organization_id', 'plan_id', 'primary_contact_id',
                                     'account_test_id', 'primary_domain_id', 'base_domain_id'],
            'app_instances'      => ['organization_id', 'application_id', 'version_id', 'plan_id',
                                     'web_server_id', 'database_server_id', 'sso_server_id',
                                     'parent_id', 'status'],
            'applications'       => ['parent_app_id'],
            'tasks'              => ['organization_id', 'application_id', 'version_id',
                                     'app_instance_id', 'status'],
            'app_versions'       => ['application_id', 'announcement_id'],
            'plans'              => ['email_server_id'],
            'new_user_code'      => ['organization_id'],
            'email_forwarders'   => ['organization_id', 'domain_id'],
            'additional_storage' => ['organization_id', 'app_instance_id'],
            'app_roles'          => ['application_id'],
            'org_backups'        => ['organization_id', 'scheduled_backup_id', 'app_instance_id',
                                     'org_server_id', 'status'],
            'backup_schedules'   => ['recurring_backup_id'],
            'recurring_backups'  => ['server_id', 'organization_id', 'application_id'],
            'org_domains'        => ['organization_id', 'app_instance_id', 'parent_domain_id',
                                     'tld_id', 'status'],
            'app_plans'          => ['application_id', 'web_server_id', 'database_server_id',
                                     'sso_server_id', 'shared_app_id'],
            'servers'            => ['app_instance_id', 'default_backup_server_id'],
            'org_servers'        => ['organization_id', 'server_id', 'backup_server_id'],
            'account_tests'      => ['organization_id', 'created_by_id'],
            'suborg_users'       => ['organization_id'],
            'org_subdomains'     => ['organization_id', 'app_instance_id', 'parent_domain_id'],
            'app_implied_roles'  => ['primary_app_role_id', 'implied_app_role_id'],
            'groups'             => ['organization_id'],
            'group_members'      => ['group_id', 'user_id'],
        ];

        foreach ($indexMap as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $col) {
                    $t->index($col);
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Step 3 — add FK constraints individually so one failure (e.g. dirty data
    // on a NOT-NULL column) does not prevent the remaining FKs from being added.
    // -------------------------------------------------------------------------
    private function addForeignKeys(): void
    {
        // users
        $this->tryAddForeign('users', 'organization_id', 'organizations', 'cascade');

        // organizations
        $this->tryAddForeign('organizations', 'parent_organization_id', 'organizations', 'null');
        $this->tryAddForeign('organizations', 'plan_id',                'plans',         'null');
        $this->tryAddForeign('organizations', 'primary_contact_id',     'users',         'null');
        $this->tryAddForeign('organizations', 'account_test_id',        'account_tests', 'null');

        // subscriptions (organization_id already indexed by existing composite index)
        $this->tryAddForeign('subscriptions', 'organization_id', 'organizations', 'cascade');

        // subscription_items (subscription_id already indexed by existing unique composite)
        $this->tryAddForeign('subscription_items', 'subscription_id', 'subscriptions', 'cascade');

        // app_instances
        $this->tryAddForeign('app_instances', 'organization_id',   'organizations', 'cascade');
        $this->tryAddForeign('app_instances', 'application_id',    'applications',  'restrict');
        $this->tryAddForeign('app_instances', 'version_id',        'app_versions',  'restrict');
        $this->tryAddForeign('app_instances', 'plan_id',           'app_plans',     'null');
        $this->tryAddForeign('app_instances', 'web_server_id',     'org_servers',   'restrict');
        $this->tryAddForeign('app_instances', 'database_server_id','org_servers',   'null');
        $this->tryAddForeign('app_instances', 'sso_server_id',     'org_servers',   'null');
        $this->tryAddForeign('app_instances', 'parent_id',         'app_instances', 'null');

        // applications
        $this->tryAddForeign('applications', 'parent_app_id', 'applications', 'null');

        // tasks
        $this->tryAddForeign('tasks', 'organization_id',  'organizations', 'cascade');
        $this->tryAddForeign('tasks', 'application_id',   'applications',  'null');
        $this->tryAddForeign('tasks', 'version_id',       'app_versions',  'null');
        $this->tryAddForeign('tasks', 'app_instance_id',  'app_instances', 'null');

        // app_versions
        $this->tryAddForeign('app_versions', 'application_id', 'applications', 'cascade');
        $this->tryAddForeign('app_versions', 'announcement_id','announcements', 'null');

        // plans
        $this->tryAddForeign('plans', 'email_server_id', 'servers', 'null');

        // new_user_code
        $this->tryAddForeign('new_user_code', 'organization_id', 'organizations', 'cascade');

        // email_forwarders
        $this->tryAddForeign('email_forwarders', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('email_forwarders', 'domain_id',       'org_domains',   'cascade');

        // additional_storage
        $this->tryAddForeign('additional_storage', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('additional_storage', 'app_instance_id', 'app_instances', 'null');

        // app_roles
        $this->tryAddForeign('app_roles', 'application_id', 'applications', 'cascade');

        // org_backups
        $this->tryAddForeign('org_backups', 'organization_id',    'organizations',   'cascade');
        $this->tryAddForeign('org_backups', 'scheduled_backup_id','backup_schedules','null');
        $this->tryAddForeign('org_backups', 'app_instance_id',    'app_instances',   'null');
        $this->tryAddForeign('org_backups', 'org_server_id',      'org_servers',     'null');

        // backup_schedules
        $this->tryAddForeign('backup_schedules', 'recurring_backup_id', 'recurring_backups', 'cascade');

        // recurring_backups
        $this->tryAddForeign('recurring_backups', 'server_id',      'servers',       'restrict');
        $this->tryAddForeign('recurring_backups', 'organization_id','organizations', 'null');
        $this->tryAddForeign('recurring_backups', 'application_id', 'applications',  'null');

        // org_domains — organization_id cascades; the reverse circular refs
        // (organizations.primary_domain_id / base_domain_id) are NOT NULL so only indexed, not FK'd
        $this->tryAddForeign('org_domains', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('org_domains', 'app_instance_id', 'app_instances', 'null');
        $this->tryAddForeign('org_domains', 'parent_domain_id','org_domains',   'null');
        $this->tryAddForeign('org_domains', 'tld_id',          'tlds',          'null');

        // app_plans — web/db/sso server columns reference servers directly (not org_servers)
        $this->tryAddForeign('app_plans', 'application_id',    'applications', 'cascade');
        $this->tryAddForeign('app_plans', 'web_server_id',     'servers',      'null');
        $this->tryAddForeign('app_plans', 'database_server_id','servers',      'null');
        $this->tryAddForeign('app_plans', 'sso_server_id',     'servers',      'null');
        $this->tryAddForeign('app_plans', 'shared_app_id',     'app_instances','null');

        // servers
        $this->tryAddForeign('servers', 'app_instance_id',        'app_instances', 'restrict');
        $this->tryAddForeign('servers', 'default_backup_server_id','servers',      'null');

        // org_servers
        $this->tryAddForeign('org_servers', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('org_servers', 'server_id',       'servers',       'restrict');
        $this->tryAddForeign('org_servers', 'backup_server_id','org_servers',   'null');

        // account_tests
        $this->tryAddForeign('account_tests', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('account_tests', 'created_by_id',   'users',         'restrict');

        // suborg_users
        $this->tryAddForeign('suborg_users', 'organization_id', 'organizations', 'cascade');

        // org_subdomains
        $this->tryAddForeign('org_subdomains', 'organization_id', 'organizations', 'cascade');
        $this->tryAddForeign('org_subdomains', 'app_instance_id', 'app_instances', 'null');
        $this->tryAddForeign('org_subdomains', 'parent_domain_id','org_domains',   'null');

        // app_implied_roles
        $this->tryAddForeign('app_implied_roles', 'primary_app_role_id', 'app_roles', 'cascade');
        $this->tryAddForeign('app_implied_roles', 'implied_app_role_id', 'app_roles', 'cascade');

        // groups
        $this->tryAddForeign('groups', 'organization_id', 'organizations', 'cascade');

        // group_members
        $this->tryAddForeign('group_members', 'group_id', 'groups', 'cascade');
        $this->tryAddForeign('group_members', 'user_id',  'users',  'cascade');
    }

    private function tryAddForeign(string $table, string $column, string $on, string $onDelete): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($column, $on, $onDelete) {
                $fk = $t->foreign($column)->references('id')->on($on);
                match ($onDelete) {
                    'cascade'  => $fk->cascadeOnDelete(),
                    'null'     => $fk->nullOnDelete(),
                    'restrict' => $fk->restrictOnDelete(),
                };
            });
        } catch (\Throwable $e) {
            Log::warning("add_foreign_keys migration: skipped FK {$table}.{$column} → {$on}: {$e->getMessage()}");
        }
    }

    // -------------------------------------------------------------------------
    // down() — drop FKs first (reverse order), then indexes
    // -------------------------------------------------------------------------
    public function down(): void
    {
        $foreignKeys = [
            'group_members'      => ['group_id', 'user_id'],
            'groups'             => ['organization_id'],
            'app_implied_roles'  => ['primary_app_role_id', 'implied_app_role_id'],
            'org_subdomains'     => ['organization_id', 'app_instance_id', 'parent_domain_id'],
            'suborg_users'       => ['organization_id'],
            'account_tests'      => ['organization_id', 'created_by_id'],
            'org_servers'        => ['organization_id', 'server_id', 'backup_server_id'],
            'servers'            => ['app_instance_id', 'default_backup_server_id'],
            'app_plans'          => ['application_id', 'web_server_id', 'database_server_id',
                                     'sso_server_id', 'shared_app_id'],
            'org_domains'        => ['organization_id', 'app_instance_id', 'parent_domain_id', 'tld_id'],
            'recurring_backups'  => ['server_id', 'organization_id', 'application_id'],
            'backup_schedules'   => ['recurring_backup_id'],
            'org_backups'        => ['organization_id', 'scheduled_backup_id', 'app_instance_id', 'org_server_id'],
            'app_roles'          => ['application_id'],
            'additional_storage' => ['organization_id', 'app_instance_id'],
            'email_forwarders'   => ['organization_id', 'domain_id'],
            'new_user_code'      => ['organization_id'],
            'plans'              => ['email_server_id'],
            'app_versions'       => ['application_id', 'announcement_id'],
            'tasks'              => ['organization_id', 'application_id', 'version_id', 'app_instance_id'],
            'applications'       => ['parent_app_id'],
            'app_instances'      => ['organization_id', 'application_id', 'version_id', 'plan_id',
                                     'web_server_id', 'database_server_id', 'sso_server_id', 'parent_id'],
            'subscription_items' => ['subscription_id'],
            'subscriptions'      => ['organization_id'],
            'organizations'      => ['parent_organization_id', 'plan_id', 'primary_contact_id', 'account_test_id'],
            'users'              => ['organization_id'],
        ];

        foreach ($foreignKeys as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $col) {
                    try {
                        $t->dropForeign([$col]);
                    } catch (\Throwable) {
                        // FK was never added (e.g. skipped due to dirty data)
                    }
                }
            });
        }

        $indexMap = [
            'users'              => ['organization_id'],
            'organizations'      => ['parent_organization_id', 'plan_id', 'primary_contact_id',
                                     'account_test_id', 'primary_domain_id', 'base_domain_id'],
            'app_instances'      => ['organization_id', 'application_id', 'version_id', 'plan_id',
                                     'web_server_id', 'database_server_id', 'sso_server_id',
                                     'parent_id', 'status'],
            'applications'       => ['parent_app_id'],
            'tasks'              => ['organization_id', 'application_id', 'version_id',
                                     'app_instance_id', 'status'],
            'app_versions'       => ['application_id', 'announcement_id'],
            'plans'              => ['email_server_id'],
            'new_user_code'      => ['organization_id'],
            'email_forwarders'   => ['organization_id', 'domain_id'],
            'additional_storage' => ['organization_id', 'app_instance_id'],
            'app_roles'          => ['application_id'],
            'org_backups'        => ['organization_id', 'scheduled_backup_id', 'app_instance_id',
                                     'org_server_id', 'status'],
            'backup_schedules'   => ['recurring_backup_id'],
            'recurring_backups'  => ['server_id', 'organization_id', 'application_id'],
            'org_domains'        => ['organization_id', 'app_instance_id', 'parent_domain_id',
                                     'tld_id', 'status'],
            'app_plans'          => ['application_id', 'web_server_id', 'database_server_id',
                                     'sso_server_id', 'shared_app_id'],
            'servers'            => ['app_instance_id', 'default_backup_server_id'],
            'org_servers'        => ['organization_id', 'server_id', 'backup_server_id'],
            'account_tests'      => ['organization_id', 'created_by_id'],
            'suborg_users'       => ['organization_id'],
            'org_subdomains'     => ['organization_id', 'app_instance_id', 'parent_domain_id'],
            'app_implied_roles'  => ['primary_app_role_id', 'implied_app_role_id'],
            'groups'             => ['organization_id'],
            'group_members'      => ['group_id', 'user_id'],
        ];

        foreach (array_reverse($indexMap) as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $col) {
                    try {
                        $t->dropIndex([$col]);
                    } catch (\Throwable) {
                        // index was never added or already dropped
                    }
                }
            });
        }
    }
};
