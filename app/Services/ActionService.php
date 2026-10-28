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
use App\Enums\TaskStatus;
use App\Exceptions\ConnectionFailedException;
use App\Integrations\Applications\CiviCRMStandalone\Actions\ProcessPermissions as ActionsProcessPermissions;
use App\Integrations\Applications\DemoApp\Actions\ProcessGroupOptions as DemoAppProcessGroupOptions;
use App\Integrations\Applications\Nextcloud\Actions\ManageAddon;
use App\Integrations\Applications\Nextcloud\Actions\ProcessGroupOptions;
use App\Integrations\Applications\Nextcloud\Actions\ProcessPermissions;
use App\Integrations\Applications\Nextcloud\Actions\ProcessUserOptions;
use App\Integrations\ServerManagers\Rancher\Actions\RunJob;
use App\Jobs\RunAction;
use App\Organization;
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
        $application = $task->application;
        $task->job_id = 0;
        $task->notified = 0;
        $task->status = TaskStatus::PENDING;
        // Whatever was running or queued under the old state is abandoned
        $task->claimed_at = null;
        $task->queued_at = null;
        $task->save();

        $action = Arr::get($this->actions, $task->action());

        if ($action && method_exists($action, 'retry')) {
            try {
                $retry = $action::retry($task);

                $task->attempts++;
                $task->error_message = '';
                $task->custom_values = $retry->custom_values;
                $task->save();

                if (is_object($retry) && method_exists($retry, 'postGenerate')) {
                    $task->status = $retry->status;
                    $retry->postGenerate($task);
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

        RunAction::dispatch($task);
    }

    public function run(Task $task)
    {
        $action = Arr::get($this->actions, $task->action());

        if ($action) {
            if (method_exists($action, 'run')) {
                $run = $action::run($task);

                // Update custom values if they've been changed
                if ($run && $run->customValues() != $task->custom_values) {
                    $task->custom_values = $run->customValues();
                    $task->save();
                }
            }
        } else {
            throw new \Exception('This action hasn\'t been registered: '.$task->action());
        }
    }

    public function complete(Task $task)
    {
        $action = Arr::get($this->actions, $task->action());
        $action_complete = null;
        if ($blocked_by = $this->waiting_for($task->getValue('waiting_for'))) {
            $error_message = __("messages.action.{$blocked_by}");
            if ($task->error_message != $error_message) {
                $task->error_message = $error_message;
                $task->save();
            }

            return;
        }

        if ($action && method_exists($action, 'complete')) {
            $action_complete = $action::complete($task);
        }

        if ($task->status === TaskStatus::COMPLETE) {
            $other_tasks = Task::whereNot('id', $task->id)->where('task_group', $task->task_group)->get();

            foreach ($other_tasks as $other_task) {
                // Pending siblings still have to satisfy their own prerequisites
                if ($other_task->status === TaskStatus::PENDING) {
                    if ($this->checkPrerequisites($other_task)->passed) {
                        RunAction::dispatch($other_task);
                    }
                } elseif ($other_task->status === TaskStatus::IN_PROGRESS) {
                    $this->complete($other_task);
                }
            }
        }

        return $action_complete;
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
            RunAction::dispatch($task);
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

    public function revert(Task $task)
    {
        $action = Arr::get($this->actions, $task->action());

        if ($action && method_exists($action, 'revert')) {
            $revert = $action::revert($task);

            // Update custom values if they've been changed
            if ($revert && $revert->customValues() != $task->custom_values) {
                $task->custom_values = $revert->customValues();
                $task->save();
            }
        }
    }

    public function clear(string $action_name, ?AppInstance $app_instance = null)
    {
        $tasks = Task::where('action_slug', $action_name)->whereIn('status', TaskStatus::openValues());

        if ($app_instance) {
            $tasks->where('app_instance_id', $app_instance->id);
        }

        $tasks->delete();
    }

    /**
     * Why $tasks (ids of tasks this one waits on) are holding it back: the
     * messages.action key to show, or null if none of them are.
     */
    private function waiting_for(?array $tasks = null): ?string
    {
        $blocked_by = null;

        foreach ($tasks ?? [] as $task) {
            $waiting_for = Task::find($task);

            if ($waiting_for && $waiting_for->status === TaskStatus::FAILED) {
                // A failed dependency won't finish by itself: say so rather
                // than showing "waiting" forever.
                return 'dependency_failed';
            }

            if ($waiting_for && $waiting_for->status !== TaskStatus::COMPLETE) {
                $blocked_by = 'waiting_for';
            }
        }

        return $blocked_by;
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

        return $prerequisites;
    }
}
