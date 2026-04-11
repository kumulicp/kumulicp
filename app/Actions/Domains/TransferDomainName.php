<?php

namespace App\Actions\Domains;

use App\Actions\Action;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Domain;
use App\Task;

class TransferDomainName extends Action
{
    public $slug = 'transfer_domain_name';

    public $status = 'pending';

    public function __construct(Organization $organization, public OrgDomain $domain, string $epp_code, float $price)
    {
        $this->organization = $organization;

        $domain->status = 'transferring';
        $domain->type = 'managed';
        $domain->source = $domain->tld?->default_driver ?? config('domains.default');
        $domain->save();

        $this->domain = $domain;

        $this->description = __('actions.transferring_domain');

        $this->setCustomValues(['domain_name' => $domain->name, 'domain_id' => $domain->id, 'price' => $price, 'epp_code' => $epp_code]);
    }

    public function postGenerate(Task $task) {}

    public static function run(Task $task)
    {
        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $price = $task->getValue('price');
        $epp_code = $task->getValue('epp_code');

        $org_domain = $organization->domains()->where('name', $domain_name)->where('status', 'transferring')->first();

        $domain = Domain::registrar($org_domain)->transfer($epp_code);
    }

    public static function retry(Task $task)
    {
        $organization = $task->organization;
        $domain_name = $task->getValue('domain_name');
        $domain = $organization->domains()->where('name', $domain_name)->first();

        $epp_code = $task->getValue('epp_code');
        $price = $task->getValue('price');

        return new self($organization, $domain, $epp_code, $price);
    }

    public static function complete(Task $task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = $task->organization->domains()
            ->where('name', $domain_name)
            ->first();

        if ($domain->status != 'transferring') {
            $task->complete();
            $task->groupNotified();
        }
    }
}
