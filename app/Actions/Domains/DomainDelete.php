<?php

namespace App\Actions\Domains;

use App\Actions\Action;
use App\Events\Domains\DomainDeleted;
use App\Organization;
use App\OrgDomain;
use App\Support\Facades\Application;
use App\Task;

class DomainDelete extends Action
{
    public $slug = 'domain_delete';

    public $domain;

    public function __construct(Organization $organization, OrgDomain $domain)
    {
        $this->organization = $organization;
        $this->domain = $domain;
        $this->setCustomValues([
            'domain_name' => $domain->name,
        ]);

        $this->description = __('actions.removing_domain', ['domain' => $domain->name]);
    }

    public static function run($task)
    {
        try {
            $domain_name = $task->getValue('domain_name');
            $domain = $task->organization->domains()->where('name', $domain_name)->first();

            // Remove sub domains
            foreach ($domain->subdomains as $subdomain) {
                if ($subdomain->app_instance) {
                    $server = Application::instance($subdomain->app_instance)->connect('web');

                    if ($server && method_exists($server, 'existsDomain')) {
                        if ($server->existsDomain()) {
                            $server->deleteDomain();
                        }

                        if ($server->hasDomainError()) {
                            throw new \Exception($server->domainError());
                        }
                    }
                }
            }

            if ($domain->app_instance) {
                $server = Application::instance($domain->app_instance)->connect('web');
                if ($server && method_exists($server, 'existsDomain')) {
                    if ($server->existsDomain()) {
                        $server->deleteDomain();
                    }

                    if ($server->hasDomainError()) {
                        throw new \Exception($server->domainError());
                    }
                }
            }
        } catch (Throwable $e) {
            $task->status = 'failed';
            $task->error_message = $e->getMessage();
            $task->save();
        }
    }

    public static function retry($task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = OrgDomain::where('organization_id', $task->organization->id)->where('name', $domain_name)->first();

        return new self($task->organization, $domain);
    }

    public static function complete(Task $task)
    {
        $domain_name = $task->getValue('domain_name');
        $domain = OrgDomain::where('organization_id', $task->organization->id)
            ->where('name', $domain_name)
            ->first();

        if ($domain) {
            $domain->subdomains()->delete();

            $domain->delete();
        }

        DomainDeleted::dispatch($task->organization, $domain_name);

        $task->complete();
        $task->groupNotified();
    }
}
