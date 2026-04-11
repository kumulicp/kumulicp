<?php

namespace App\Services;

use App\Actions\Action;
use App\Actions\AddWebDomain;
use App\Actions\Apps\ApplicationActivate;
use App\Actions\Apps\ApplicationAddonActivate;
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
use App\Actions\EmailSettingsUpdate;
use App\Actions\Organizations\DeactivateOrganization;
use App\Actions\Organizations\DeleteOrganization;
use App\Actions\Organizations\InvoiceOrganization;
use App\Actions\Organizations\SubscriptionUpdate;
use App\Actions\Organizations\UpdateSubscriptionSettings;
use App\Actions\Prerequisites;
use App\Actions\Servers\ServerActivate;
use App\Actions\Tests\ClearTestAccounts;
use App\Actions\Tests\CreateTests;
use App\AppInstance;
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
            'application_upgrade' => ApplicationUpgrade::class,
            'application_delete' => ApplicationDelete::class,
            'application_domain_checks' => ApplicationDomainChecks::class,
            'application_deactivate' => ApplicationDeactivate::class,
            'mass_application_upgrade' => MassApplicationUpgrade::class,
            'email_settings_update' => EmailSettingsUpdate::class,
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
            'application_addon_activate' => ApplicationAddonActivate::class,
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
        'rancher' => [
            'run_rancher_job' => RunJob::class,
        ],
    ];

    public function get()
    {
        return $this;
    }

    public function exists(Organization $organization, string $category, string $action)
    {
        $tasks = $organization->tasks()
            ->where('action_group', $category)
            ->where('action_slug', $action)
            ->where('status', '!=', 'complete')
            ->count();

        return $tasks > 0;
    }

    public function retry(Task $task)
    {
        $application = $task->application;
        $task->job_id = 0;
        $task->notified = 0;
        $task->status = 'pending';
        $task->save();

        $action = Arr::get($this->actions, $task->action());

        if ($action && method_exists($action, 'retry')) {
            try {
                $retry = $action::retry($task);

                $task->attempts++;
                $task->error_message = '';
                $task->custom_values = $retry->custom_values;
                $task->save();

                if ($retry && method_exists($retry, 'postGenerate')) {
                    $task->status = $retry->status;
                    $retry->postGenerate($task);
                }
            } catch (ConnectionFailedException $e) {
                $task->job_id = 0;
                $task->status = 'pending';
                $task->error_message = $e->getMessage();
                $task->error_code = 'connection_failed';
                $task->save();
            } catch (Throwable $e) {
                $task->error_message = $e->getMessage();
                $task->status = 'failed';
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
                if ($run && $run->customValues() != $task->organization_values) {
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
        if ($this->waiting_for($task->getValue('waiting_for'))) {
            $error_message = __('messages.action.waiting_for');
            if ($task->error_message != $error_message) {
                $task->error_message = $error_message;
                $task->save();
            }

            return;
        }

        if ($action && method_exists($action, 'complete')) {
            $action_complete = $action::complete($task);
        }

        if ($task->status === 'complete') {
            $other_tasks = Task::whereNot('id', $task->id)->where('task_group', $task->task_group)->get();

            foreach ($other_tasks as $other_task) {
                if ($other_task->status === 'pending') {
                    RunAction::dispatch($other_task);
                } elseif ($other_task->status === 'in_progress') {
                    $this->complete($other_task);
                }
            }
        }

        return $action_complete;
    }

    public function register($name, $action)
    {
        if (class_exists($action) && is_subclass_of($action, Action::class)) {
            if (Arr::has($this->actions, $name)) {
                array_push($this->actions[$name], $action);
            } else {
                Arr::set($this->actions, $name, $action);
            }

            return $this->actions['nextcloud'];
        }

        throw new \Exception(__('messages.exception.action_not_subclass', ['action' => $action]));
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
        $task->custom_values = $action->customValues();
        $task->background = $background ?? $action->background;
        $task->save();

        if (method_exists($action, 'postGenerate')) {
            $task->attempts = 1;
            $task->save();

            try {
                $action->postGenerate($task);
            } catch (\Throwable $e) {
                report($e);
                $task->error_message = $e->getMessage();
                $task->status = 'failed';
                $task->save();
            }
        }

        if (! $delay && $this->checkPrerequisites($task)->passed) {
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
            } catch (\Throwable $e) {
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
            if ($revert && $revert->customValues() != $task->organization_values) {
                $task->custom_values = $revert->customValues();
                $task->save();
            }
        }
    }

    public function clear(string $action_name, ?AppInstance $app_instance = null)
    {
        $tasks = Task::where('action_slug', $action_name)->where('status', '!=', 'complete');

        if ($app_instance) {
            $tasks->where('app_instance_id', $app_instance->id);
        }

        $tasks->delete();
    }

    private function waiting_for(?array $tasks = null)
    {
        if (! is_null($tasks)) {
            foreach ($tasks as $task) {
                $waiting_for = Task::find($task);

                if ($waiting_for && $waiting_for->status != 'complete') {
                    return true;
                }
            }
        }

        return false;
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
