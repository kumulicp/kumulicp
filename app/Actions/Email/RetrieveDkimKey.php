<?php

namespace App\Actions\Email;

use App\Actions\Action;
use App\Actions\Prerequisites;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Task;

class RetrieveDkimKey extends Action
{
    public $slug = 'retrieve_dkim_key';

    public $status = 'pending';

    public function __construct(Organization $organization, OrgDomain $domain)
    {
        $this->organization = $organization;

        $this->description = __('actions.retrieving_dkim_key', ['domain' => $domain->name]);
        $this->background = true;
        $this->setCustomValues(['domain_name' => $domain->name]);

        $prereqs = new Prerequisites;
        $prereqs->add_subscription_active();
        $this->prerequisites = $prereqs->get();
    }

    public static function run(Task $task)
    {
        $organization = $task->organization;
        $domain = $organization->domains()->where('name', $task->getValue('domain_name'))->first();

        $retrieve_dkim_key = new self($organization, $domain);

        $organization_email_server = Domain::connect($domain, 'email');
        $email_response = $organization_email_server->createDkimKey($domain);

        $retrieve_dkim_key->addCustomValue(['job_id' => $email_response['job_id']]);

        return $retrieve_dkim_key;
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $domain = $organization->domains()->where('name', $task->getValue('domain_name'))->first();

        return new self($organization, $domain);
    }

    public static function complete(Task $task)
    {
        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $domain = $organization->domains()->where('name', $domain_name)->first();

        if ($domain->dkim_public_key) {
            $task->groupNotified();
            $task->complete();
        }
    }
}
