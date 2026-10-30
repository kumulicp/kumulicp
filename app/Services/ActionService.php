<?php

namespace App\Services;

use App\Actions\Action;
use App\Actions\AddWebDomain;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationDatabaseMigrate;
use App\Actions\Apps\ApplicationDeactivate;
use App\Actions\Apps\ApplicationDelete;
use App\Actions\Apps\ApplicationDomainChecks;
use App\Actions\Apps\ApplicationSSOSetup;
use App\Actions\Apps\ApplicationUpdate;
use App\Actions\Apps\ApplicationUpdateJob;
use App\Actions\Apps\ApplicationUpgrade;
use App\Actions\Apps\MassApplicationUpgrade;
use App\Actions\Apps\ProcessCustomizations;
use App\Actions\Domains\DomainDelete;
use App\Actions\Domains\RegisterDomainName;
use App\Actions\Domains\TransferDomainName;
use App\Actions\Domains\UpdateDnsRecords;
use App\Actions\DummyAction;
use App\Actions\Email\AddEmailDomain;
use App\Actions\Email\RetrieveDkimKey;
use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\InvoiceOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Organizations\UpdateSubscriptionSettings;
use App\Actions\Prerequisites;
use App\Actions\Security\RunSecurityScan;
use App\Actions\Servers\ServerActivate;
use App\Actions\Tests\ClearTestAccounts;
use App\Actions\Tests\CreateTests;
use App\AppInstance;
use App\Contracts\Actions\TaskAction;
use App\Enums\TaskStatus;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\CiviCRMStandalone\Actions\ProcessPermissions as ActionsProcessPermissions;
use App\Integrations\Applications\DemoApp\Actions\ProcessGroupOptions as DemoAppProcessGroupOptions;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;
use App\Integrations\Applications\Nextcloud\Actions\ProcessGroupOptions;
use App\Integrations\Applications\Nextcloud\Actions\ProcessPermissions;
use App\Integrations\Applications\Nextcloud\Actions\ProcessUserOptions;
use App\Integrations\ServerManagers\Rancher\Actions\RunJob;
use App\Organization;
use App\Services\Tasks\Handlers\LegacyHandler;
use App\Services\Tasks\Handlers\NativeHandler;
use App\Services\Tasks\Handlers\TaskHandler;
use App\Services\Tasks\TaskDispatcher;
use App\Task;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActionService
{
    private $actions = [
        'system' => [
            'add_web_domain' => AddWebDomain::class,
            'add_email_domain' => AddEmailDomain::class,
            'domain_delete' => DomainDelete::class,
            'subscription_update' => SubscriptionUpdate::class,
            'application_activate' => ApplicationActivate::class,
            'application_update' => ApplicationUpdate::class,
            'application_database_migrate' => ApplicationDatabaseMigrate::class,
            'application_upgrade' => ApplicationUpgrade::class,
            'application_delete' => ApplicationDelete::class,
            'application_domain_checks' => ApplicationDomainChecks::class,
            'application_deactivate' => ApplicationDeactivate::class,
            'mass_application_upgrade' => MassApplicationUpgrade::class,
            'create_tests' => CreateTests::class,
            'clear_tests' => ClearTestAccounts::class,
            'process_customizations' => ProcessCustomizations::class,
            'retrieve_dkim_key' => RetrieveDkimKey::class,
            'register_domain_name' => RegisterDomainName::class,
            'transfer_domain_name' => TransferDomainName::class,
            'invoice_organization' => InvoiceOrganization::class,
            'delete_organization' => DeleteOrganization::class,
            'update_dns_records' => UpdateDnsRecords::class,
            'dummy_action' => DummyAction::class,
            'update_subscription_settings' => UpdateSubscriptionSettings::class,
            'application_update_job' => ApplicationUpdateJob::class,
            'server_activate' => ServerActivate::class,
            'deactivate_organization' => DeactivateOrganization::class,
            'application_sso_setup' => ApplicationSSOSetup::class,
        ],
        'nextcloud' => [
            'process_group_options' => ProcessGroupOptions::class,
            'process_permissions' => ProcessPermissions::class,
            'manage_addon' => ManageAddon::class,
            'process_user_options' => ProcessUserOptions::class,
        ],
        'wordpress' => [
            'process_permissions' => \App\Integrations\Applications\Wordpress\Actions\ProcessPermissions::class,
        ],
        'civicrm-standalone' => [
            'process_permissions' => ActionsProcessPermissions::class,
        ],
        'demo_app' => [
            'process_group_options' => DemoAppProcessGroupOptions::class,
        ],
        'rancher' => [
            'run_rancher_job' => RunJob::class,
        ],
        'security_scan' => [
            'run_scan' => RunSecurityScan::class,
        ],
    ];

    public function get()
    {
        return $this;
    }

    public function classFor(Task $task): ?string
    {
        return Arr::get($this->actions, $task->action());
    }

    /**
     * How to drive the task's action, or null if it isn't a registered one.
     */
    public function handlerFor(Task $task): ?TaskHandler
    {
        $class = $this->classFor($task);

        if (! $class) {
            return null;
        }

        return is_subclass_of($class, TaskAction::class) ? new NativeHandler($class) : new LegacyHandler($class);
    }

    public function exists(Organization $organization, string $category, string $action)
    {
        $tasks = $organization->tasks()
            ->where('action_group', $category)
            ->where('action_slug', $action)
            ->whereIn('status', TaskStatus::openValues())
            ->count();

        return $tasks > 0;
    }

    public function retry(Task $task)
    {
        $task->job_id = 0;
        $task->notified = 0;
        $task->status = TaskStatus::PENDING;
        // Whatever was running or queued under the old state is abandoned, and the
        // run will start whatever it waits on again
        $task->claimed_at = null;
        $task->queued_at = null;
        $task->next_attempt_at = null;
        $task->blocked_from = null;
        $task->save();
        $task->syncFinishDependencies([]);

        if ($handler = $this->handlerFor($task)) {
            try {
                if ($retry = $handler->retry($task)) {
                    $task->attempts++;
                    $task->error_message = '';
                    $task->custom_values = $retry->custom_values;
                    $task->save();

                    if (method_exists($retry, 'postGenerate')) {
                        $task->status = $retry->status;
                        $retry->postGenerate($task);
                    }
                }
            } catch (ConnectionFailedException $e) {
                $task->job_id = 0;
                $task->status = TaskStatus::PENDING;
                $task->error_message = $e->getMessage();
                $task->error_code = 'connection_failed';
                $task->save();
            } catch (Throwable $e) {
                $task->error_message = $e->getMessage();
                $task->status = TaskStatus::FAILED;
                $task->save();

                report($e);
            }
        }

        $task->save();

        app(TaskDispatcher::class)->queue($task);
    }

    /**
     * Registers an action class under "group.slug" (e.g. "nextcloud.manage_addon").
     */
    public function register(string $name, string $action): void
    {
        if (! str_contains($name, '.')) {
            throw new \InvalidArgumentException("Action must be registered as \"group.slug\", got \"{$name}\"");
        }

        if (! class_exists($action) || ! is_subclass_of($action, Action::class)) {
            throw new \Exception(__('messages.exception.action_not_subclass', ['action' => $action]));
        }

        Arr::set($this->actions, $name, $action);
    }

    public function execute(Action $action, ?Task $parent_task = null, ?bool $background = null, bool $delay = false)
    {
        if ($action->task_exists()) {
            return;
        }

        $task_group = rand();

        $task = new Task;
        $task->organization_id = $action->organization->id;
        $task->application_id = $action->application('id');
        $task->version_id = $action->version('id');
        $task->app_instance_id = $action->app_instance('id');
        $task->custom_instructions = $action->steps ?? null;
        $task->action_slug = $action->slug;
        $task->task_group = $parent_task ? $parent_task->task_group : $task_group;
        $task->action_group = $action->action_group;
        $task->description = $action->description;
        $task->prerequisites = $action->prerequisites;
        $task->status = $action->status;
        $task->deadline_at = $action->deadline_minutes ? now()->addMinutes($action->deadline_minutes) : null;
        $task->custom_values = $action->customValues();
        $task->background = $background ?? $action->background;
        $task->save();

        // A delayed task with a parent starts once the parent is complete
        if ($parent_task && $delay) {
            $action->dependsOn($parent_task);
        }

        $task->dependOn($action->start_dependencies, Task::GATE_START);

        if ($action->finish_dependencies !== null) {
            $task->syncFinishDependencies($action->finish_dependencies);
        }

        if (method_exists($action, 'postGenerate')) {
            $task->attempts = 1;
            $task->save();

            try {
                $action->postGenerate($task);
            } catch (Throwable $e) {
                report($e);
                $task->error_message = $e->getMessage();
                $task->status = TaskStatus::FAILED;
                $task->save();
            }
        }

        // A task postGenerate() just failed must stay failed: running it anyway
        // (and checkPrerequisites() wiping its error message) hides the failure.
        if ($task->status !== TaskStatus::FAILED && ! $delay && $this->checkPrerequisites($task)->passed) {
            app(TaskDispatcher::class)->queue($task);
        }

        Log::info(__('labels.task').': '.$task->description, ['organization_id' => $task->organization->id]);

        return $task;
    }

    public function dispatch($category, $action, $params, $parent_task = null)
    {
        $action = Arr::get($this->actions, implode('.', [$category, $action]));
        if ($action) {
            try {
                $action = new $action(...$params);
                $action->action_group = $category;
            } catch (Throwable $e) {
                report($e);

                return;
            }
        } else {
            return;
        }

        return $this->execute($action, $parent_task);
    }

    public function clear(string $action_name, ?AppInstance $app_instance = null)
    {
        $tasks = Task::where('action_slug', $action_name)->whereIn('status', TaskStatus::openValues());

        if ($app_instance) {
            $tasks->where('app_instance_id', $app_instance->id);
        }

        $tasks->delete();
    }

    public function checkPrerequisites(Task $task)
    {
        $task->error_message = '';
        $task->error_code = '';
        $task->save();
        $prerequisites = new Prerequisites($task);

        /* Check prerequisites */
        if ($task->prerequisites) {
            $task_prereqs = json_decode($task->prerequisites, true);
            foreach ($task_prereqs['prereqs'] as $prereq) {
                if (array_key_exists('type', $prereq) && $prereq['type']) {
                    $prerequisites->check($prereq['type'], $prereq);
                }
            }
        }

        return $prerequisites->checkDependencies();
    }
}
