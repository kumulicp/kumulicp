<?php

namespace App\Actions\Domains;

use App\Actions\Action;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Task;

class UpdateDnsRecords extends Action
{
    public $slug = 'update_dns_records';

    public $background = true;

    public $status = 'in_progress';

    public function __construct(Organization $organization, public OrgDomain $domain)
    {
        $this->organization = $organization;
        $this->domain = $domain;

        $this->description = __('actions.updating_dns_records', ['domain' => $domain->name]);

        $this->setCustomValues(['domain_name' => $domain->name, 'domain_id' => $domain->id]);
    }

    public static function run(Task $task)
    {
        $organization = $task->organization;
        $domain = OrgDomain::find($task->getValue('domain_id'));

        $dns = Domain::registrar($domain)->updateDNS();

        $task->complete();
        $task->groupNotified();
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $domain = $organization->domains()->where('name', $domain_name)->first();

        return new self($organization, $domain);
    }

    public static function complete(Task $task)
    {
        $task->complete();
        $task->notified();
    }
}
