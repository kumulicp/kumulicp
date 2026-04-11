<?php

namespace App\Actions;

use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Task;
use App\Tld;

class AddWebDomain extends Action
{
    public $slug = 'add_web_domain';

    public $domain;

    public function __construct(Organization $organization, OrgDomain $domain)
    {
        $this->organization = $organization;
        $this->domain = $domain;

        $this->description = __('actions.adding_domain', ['domain' => $domain->name]);
        $this->background = true;

        $this->setCustomValues(['domain_name' => $domain->name]);

        $prereqs = new Prerequisites;
        $prereqs->add_subscription_active();
        $this->prerequisites = $prereqs->get();
    }

    public static function run(Task $task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = OrgDomain::where('organization_id', $task->organization_id)->where('name', $domain_name)->first();
        $tld = Tld::where('name', Domain::getTld($domain->name))->first();

        try {
            if (! $domain) {
                throw new \Exception(__('organization.domain.denied.exists'));
            }

            $app_instances = $task->organization->app_instances;
            foreach ($app_instances as $app) {
                $app_domain = OrgDomain::where('app_instance_id', $app->id)->where('parent_domain_id', $domain->id)->where('type', 'app')->first();

                if (! $app_domain) {
                    $app_domain = new OrgDomain;
                    $app_domain->parent_domain_id = $domain->id;
                    $app_domain->app_instance_id = $app->id;
                    $app_domain->tld_id = $tld->id;
                    $app_domain->name = $app->name.'.'.$domain->name;
                    $app_domain->is_managed = $domain->is_managed;
                    $app_domain->source = $domain->source;
                    $app_domain->type = 'app';
                    $app_domain->status = 'active';
                    $app_domain->save();
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->task->error_message = $e->getMessage();
            $this->task->status = 'failed';
            $this->task->save();
        }
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $domain = OrgDomain::where('organization_id', $organization->id)
            ->where('name', $task->getValue('domain_name'))
            ->first();

        return new self($organization, $domain);
    }

    public static function complete(Task $task)
    {
        $task->complete();
        $task->groupNotified();
    }
}
