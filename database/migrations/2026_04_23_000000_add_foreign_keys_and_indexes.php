<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // organizations self-ref + plan + primary_contact + account_test
        // primary_domain_id / base_domain_id are NOT NULL and reference org_domains which in turn
        // references organizations, making a cascade loop impossible — indexes only for those two.
        Schema::table('organizations', function (Blueprint $table) {
            $table->index('parent_organization_id');
            $table->index('plan_id');
            $table->index('primary_contact_id');
            $table->index('account_test_id');
            $table->index('primary_domain_id');
            $table->index('base_domain_id');
        });

        // app_instances
        Schema::table('app_instances', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('application_id');
            $table->index('version_id');
            $table->index('plan_id');
            $table->index('web_server_id');
            $table->index('database_server_id');
            $table->index('sso_server_id');
            $table->index('parent_id');
        });

        // applications — self-referential parent hierarchy
        Schema::table('applications', function (Blueprint $table) {
            $table->index('parent_app_id');
        });

        // tasks
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('application_id');
            $table->index('version_id');
            $table->index('app_instance_id');
        });

        // app_versions
        Schema::table('app_versions', function (Blueprint $table) {
            $table->index('application_id');
            $table->index('announcement_id');
        });

        // plans
        Schema::table('plans', function (Blueprint $table) {
            $table->index('email_server_id');
        });

        // new_user_code
        Schema::table('new_user_code', function (Blueprint $table) {
            $table->index('organization_id');
        });

        // email_forwarders
        Schema::table('email_forwarders', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('domain_id');
        });

        // additional_storage
        Schema::table('additional_storage', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('app_instance_id');
        });

        // app_roles
        Schema::table('app_roles', function (Blueprint $table) {
            $table->index('application_id');
        });

        // org_backups
        Schema::table('org_backups', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('scheduled_backup_id');
            $table->index('app_instance_id');
            $table->index('org_server_id');
        });

        // backup_schedules
        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->index('recurring_backup_id');
        });

        // recurring_backups
        Schema::table('recurring_backups', function (Blueprint $table) {
            $table->index('server_id');
            $table->index('organization_id');
            $table->index('application_id');
        });

        // org_domains — organization_id cascades so domains are cleaned up with the org;
        // the reverse FKs (organizations.primary_domain_id / base_domain_id) are NOT NULL and
        // therefore cannot use nullOnDelete, so those are index-only (added above in organizations)
        Schema::table('org_domains', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('app_instance_id');
            $table->index('parent_domain_id');
            $table->index('tld_id');
        });

        // app_plans — web/db/sso server columns reference servers directly (not org_servers)
        Schema::table('app_plans', function (Blueprint $table) {
            $table->index('application_id');
            $table->index('web_server_id');
            $table->index('database_server_id');
            $table->index('sso_server_id');
            $table->index('shared_app_id');
        });

        // servers — app_instance_id is restrict so you cannot delete the control-panel
        // app_instance while a server record still points at it
        Schema::table('servers', function (Blueprint $table) {
            $table->index('app_instance_id');
            $table->index('default_backup_server_id');
        });

        // org_servers
        Schema::table('org_servers', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('server_id');
            $table->index('backup_server_id');
        });

        // account_tests
        Schema::table('account_tests', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('created_by_id');
        });

        // suborg_users
        Schema::table('suborg_users', function (Blueprint $table) {
            $table->index('organization_id');
        });

        // org_subdomains
        Schema::table('org_subdomains', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('app_instance_id');
            $table->index('parent_domain_id');
        });

        // app_implied_roles — both sides cascade so removing a role cleans up all its implications
        Schema::table('app_implied_roles', function (Blueprint $table) {
            $table->index('primary_app_role_id');
            $table->index('implied_app_role_id');
        });

        // groups
        Schema::table('groups', function (Blueprint $table) {
            $table->index('organization_id');
        });

        // group_members
        Schema::table('group_members', function (Blueprint $table) {
            $table->index('group_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('app_implied_roles', function (Blueprint $table) {
            $table->dropForeign(['primary_app_role_id']);
            $table->dropForeign(['implied_app_role_id']);
            $table->dropIndex(['primary_app_role_id']);
            $table->dropIndex(['implied_app_role_id']);
        });

        Schema::table('org_subdomains', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['app_instance_id']);
            $table->dropForeign(['parent_domain_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['app_instance_id']);
            $table->dropIndex(['parent_domain_id']);
        });

        Schema::table('suborg_users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('account_tests', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['created_by_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['created_by_id']);
        });

        Schema::table('org_servers', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['server_id']);
            $table->dropForeign(['backup_server_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['server_id']);
            $table->dropIndex(['backup_server_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropForeign(['app_instance_id']);
            $table->dropForeign(['default_backup_server_id']);
            $table->dropIndex(['app_instance_id']);
            $table->dropIndex(['default_backup_server_id']);
        });

        Schema::table('app_plans', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['web_server_id']);
            $table->dropForeign(['database_server_id']);
            $table->dropForeign(['sso_server_id']);
            $table->dropForeign(['shared_app_id']);
            $table->dropIndex(['application_id']);
            $table->dropIndex(['web_server_id']);
            $table->dropIndex(['database_server_id']);
            $table->dropIndex(['sso_server_id']);
            $table->dropIndex(['shared_app_id']);
        });

        Schema::table('org_domains', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['app_instance_id']);
            $table->dropForeign(['parent_domain_id']);
            $table->dropForeign(['tld_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['app_instance_id']);
            $table->dropIndex(['parent_domain_id']);
            $table->dropIndex(['tld_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('recurring_backups', function (Blueprint $table) {
            $table->dropForeign(['server_id']);
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['application_id']);
            $table->dropIndex(['server_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['application_id']);
        });

        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->dropForeign(['recurring_backup_id']);
            $table->dropIndex(['recurring_backup_id']);
        });

        Schema::table('org_backups', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['scheduled_backup_id']);
            $table->dropForeign(['app_instance_id']);
            $table->dropForeign(['org_server_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['scheduled_backup_id']);
            $table->dropIndex(['app_instance_id']);
            $table->dropIndex(['org_server_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('app_roles', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropIndex(['application_id']);
        });

        Schema::table('additional_storage', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['app_instance_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['app_instance_id']);
        });

        Schema::table('email_forwarders', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['domain_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['domain_id']);
        });

        Schema::table('new_user_code', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['email_server_id']);
            $table->dropIndex(['email_server_id']);
        });

        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['announcement_id']);
            $table->dropIndex(['application_id']);
            $table->dropIndex(['announcement_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['application_id']);
            $table->dropForeign(['version_id']);
            $table->dropForeign(['app_instance_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['application_id']);
            $table->dropIndex(['version_id']);
            $table->dropIndex(['app_instance_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropForeign(['parent_app_id']);
            $table->dropIndex(['parent_app_id']);
        });

        Schema::table('app_instances', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['application_id']);
            $table->dropForeign(['version_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['web_server_id']);
            $table->dropForeign(['database_server_id']);
            $table->dropForeign(['sso_server_id']);
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['organization_id']);
            $table->dropIndex(['application_id']);
            $table->dropIndex(['version_id']);
            $table->dropIndex(['plan_id']);
            $table->dropIndex(['web_server_id']);
            $table->dropIndex(['database_server_id']);
            $table->dropIndex(['sso_server_id']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['status']);
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['parent_organization_id']);
            $table->dropForeign(['plan_id']);
            $table->dropForeign(['primary_contact_id']);
            $table->dropForeign(['account_test_id']);
            $table->dropIndex(['parent_organization_id']);
            $table->dropIndex(['plan_id']);
            $table->dropIndex(['primary_contact_id']);
            $table->dropIndex(['account_test_id']);
            $table->dropIndex(['primary_domain_id']);
            $table->dropIndex(['base_domain_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
        });
    }
};
