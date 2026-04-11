<?php

namespace App\Actions\Domains;

use App\Actions\Action;
use App\Actions\Organizations\InvoiceOrganization;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Action as ActionFacade;
use App\Support\Facades\Domain;
use App\Task;

class RegisterDomainName extends Action
{
    public $slug = 'register_domain_name';

    public $status = 'pending';

    public function __construct(Organization $organization, public OrgDomain $domain, float $price, int $years, $extended_attributes = null)
    {
        $this->organization = $organization;
        $this->domain = $domain;

        $this->description = __('actions.registering_domain', ['domain' => $domain->name]);

        $this->setCustomValues(['domain_name' => $domain->name, 'domain_id' => $domain->id, 'price' => $price, 'years' => $years, 'extended_attributes' => $extended_attributes]);
    }

    public static function run(Task $task)
    {
        $price = $task->getValue('price');
        $years = $task->getValue('years');
        $extended_attributes = $task->getValue('extended_attributes');
        $domain_name = $task->getValue('domain_name');
        $organization = $task->organization;
        $org_domain = $organization->domains()->where('name', $domain_name)->where('status', 'registering')->first();

        if (! $org_domain) {
            $task->status = 'failed';
            $task->error_code = 'no_org_domain';
            $task->error_message = __('organization.domain.denied.exists');
            $task->save();

            return;
        }

        $domain = Domain::registrar($org_domain->tld)->register($org_domain, $years, $extended_attributes);

        ActionFacade::execute(new InvoiceOrganization($organization, __('actions.domain_registration_fee', ['domain' => $org_domain->name]), $price), $task);
        ActionFacade::execute(new UpdateDnsRecords($organization, $org_domain), $task);
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $domain = $organization->domains()->where('name', $domain_name)->first();

        $price = $task->getValue('price');
        $years = $task->getValue('years');
        $extended_attributes = $task->getValue('extended_attributes');

        return new self($organization, $domain, $price, $years, $extended_attributes);
    }

    public static function complete(Task $task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = $task->organization->domains()
            ->where('name', $domain_name)
            ->first();

        if (! $domain) {
            $task->delete();

            return;
        }

        $domain->status = 'active';
        $domain->save();

        $task->complete();
        $task->notified();
    }
}
