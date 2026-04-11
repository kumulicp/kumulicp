<?php

namespace App\Actions\Email;

use App\Actions\Action;
use App\Actions\Domains\UpdateDnsRecords;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Domain;
use App\Support\Facades\Email;
use App\Task;

class AddEmailDomain extends Action
{
    public $slug = 'add_email_domain';

    public $background = false;

    public function __construct(Organization $organization, public OrgDomain $domain)
    {
        $this->organization = $organization;
        $this->domain = $domain;

        $this->description = __('actions.activating_email', ['domain' => $domain->name]);

        $this->setCustomValues(['domain_name' => $domain->name]);

        $domain->email_status = 'activating';
        $domain->save();
    }

    public static function run(Task $task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = $task->organization->domains()->where('name', $domain_name)->first();

        if ($domain) {
            $email_server = Domain::emailServer($domain);
            $organization_email_server = $email_server->get();
            $server = $email_server->connect();

            // Add customer if not already existing
            if (! $server->existsOrganization()) {
                $organization_response = $server->addOrganization();

                $organization_email_server->server_customer_id = $organization_response['customerid'];
                $organization_email_server->save();
            } elseif (is_null($organization_email_server->server_customer_id)) {
                $organization_response = $server->organization();
                $organization_email_server->server_customer_id = $organization_response['customerid'];
                $organization_email_server->save();
            }

            if (! $server->existsDomain($domain)) {
                $domain_response = $server->addDomain($domain, 'email');
                $domain->email_server_domain_id = $domain_response['id'];
            } else {
                $domain_update_response = $server->updateDomain($domain, 'email');

                if (! $domain->email_server_domain_id) {
                    $domain_response = $server->domain($domain);
                    $domain->email_server_domain_id = $domain_response['id'];
                }
            }

            $retrieve_dkim_key = ActionFacade::execute(new RetrieveDkimKey($task->organization, $domain), $task);
        }
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
        $passed = false;

        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $domain = $organization->domains()->where('name', $domain_name)->first();

        // Check if dkim key added
        if ($domain) {
            $dns_check = Email::checkEmailDNSSettings($domain);
            if ($domain->dkim_public_key && $dns_check) {
                if ($domain->type == 'managed') {
                    ActionFacade::execute(new UpdateDnsRecords($organization, $domain), $task);
                }

                $passed = true;
            } elseif (! $dns_check && $domain->dkim_public_key && $domain->email_status != 'waiting_dns') {
                $domain->email_status = 'waiting_dns';
                $domain->save();
            }
        }

        if ($passed) {
            $domain->email_status = 'active';
            $domain->email_enabled = true;
            $domain->save();

            $task->complete();
            $task->groupNotified();
        }
    }
}
