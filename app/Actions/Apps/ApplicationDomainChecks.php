<?php

namespace App\Actions\Apps;

use App\Actions\Action;
use App\AppInstance;
use App\Events\Apps\AppInstanceDomainChanged;
use App\OrgSubdomain;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Application;
use App\Support\Facades\Domain;
use App\Task;

class ApplicationDomainChecks extends Action
{
    public $slug = 'application_domain_checks';

    public $app_version = '';

    public $replace = true;

    public function __construct(AppInstance $app_instance, OrgSubdomain $domain)
    {
        $this->organization = $app_instance->organization;
        $this->app_instance = $app_instance;
        $this->setCustomValues(['domain' => $domain->id]);
        $this->status = 'in_progress';

        $app_server = Application::instance($app_instance)->server('web');
        $this->description = __('actions.waiting_for_domain_ip', ['domain' => $domain->name, 'ip' => $app_server->server->ip]);
    }

    public static function run(Task $task) {}

    public static function retry(Task $task)
    {
        $domain = OrgSubdomain::find($task->getValue('domain'));

        return new self($task->app_instance, $domain);
    }

    public static function complete(Task $task)
    {
        $domain = OrgSubdomain::find($task->getValue('domain'));
        $app_instance = $task->app_instance()->with('web_server.server')->first();

        if (! $domain) {
            $task->delete();

            return;
        }

        if (Domain::ipPointsToServer($domain, $app_instance->web_server->server)) {
            $domain->app_instance_id = $task->app_instance_id;
            $domain->save();

            $app_instance->primary_domain()->associate($domain);
            $app_instance->save();

            $update_domain = ActionFacade::execute(new ApplicationUpdateJob($app_instance, 'update_domain'));
            $update_app = ActionFacade::execute(new ApplicationUpdate($app_instance), $update_domain, true);

            AppInstanceDomainChanged::dispatch($app_instance);

            $task->complete();
            $task->groupNotified();
        } else {
            $task->error_message = __('messages.action.domain_wrong_ip', ['domainip' => gethostbyname($domain->name), 'serverip' => $app_instance->web_server->server->ip]);
            $task->save();
        }
    }
}
