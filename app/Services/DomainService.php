<?php

namespace App\Services;

use App\Actions\Apps\ApplicationUpdate;
use App\AppInstance;
use App\Integrations\Registrars\Connect\Interfaces\DomainsInterface;
use App\Integrations\Registrars\Namecheap\Interfaces\RegistrarInterface;
use App\Organization;
use App\OrgDomain;
use App\OrgSubdomain;
use App\Server;
use App\Support\Facades\Action;
use App\Support\Facades\ServerInterface;
use App\Tld;
use Illuminate\Support\Arr;

class DomainService
{
    private array $drivers = [
        'default' => DomainsInterface::class,
        'namecheap' => RegistrarInterface::class,
    ];

    private function driver(string $name)
    {
        if (Arr::has($this->drivers, $name)) {
            return new $this->drivers[$name];
        }

        throw new \Exception(__('messages.exception.no_domain_driver', ['name' => $name]));
    }

    public function register(string $name, mixed $class)
    {
        if (class_exists($class)) {
            $this->drivers[$name] = $class;
        }
    }

    public function add(Organization $organization, string $name, string $source, string $type, string $status, ?OrgDomain $parent_domain = null, ?AppInstance $app_instance = null)
    {
        $org_domain = new OrgDomain;
        $org_domain->organization_id = $organization->id;
        $org_domain->name = $name;
        $org_domain->source = $source;
        $org_domain->status = $status;
        $org_domain->type = $type;
        if ($parent_domain) {
            $org_domain->parent_domain_id = $parent_domain->id;
        }
        if ($app_instance) {
            $org_domain->app_instance_id = $app_instance->id;
        }
        if ($this->isIntegratedRegistrar($org_domain)) {
            $org_domain->tld_id = Tld::where('name', $this->getTld($name))->first()->id;
        }
        $org_domain->save();

        $subdomain = new OrgSubdomain;
        $subdomain->organization()->associate($organization);
        $subdomain->domain()->associate($org_domain);
        $subdomain->host = '@';
        $subdomain->name = $name;
        $subdomain->type = $type === 'connection' ? 'app' : 'custom';
        $subdomain->save();

        return $this->registrar($org_domain);
    }

    public function registrar(OrgDomain|Tld|string|null $domain = null)
    {
        switch (gettype($domain)) {
            case 'NULL':
                $driver = config('domains.default');
                break;
            case 'object':
                if (is_a($domain, OrgDomain::class)) {
                    if (in_array($domain->type, ['default', 'connection']) || ($domain->type == 'app' && in_array($domain->parent_domain->type, ['default', 'connection']))) {
                        $driver = 'default';
                    } else {
                        $driver = $domain->source;
                    }

                    return $this->driver($driver)->select($domain);
                } elseif (is_a($domain, Tld::class)) {
                    $driver = $domain->default_driver;
                }
                break;
            case 'string':
                $driver = $domain;
                break;
        }

        return $this->driver($driver);
    }

    public function ip(OrgDomain $domain)
    {
        return gethostbyname($domain->name);
    }

    public function emailServer(OrgDomain $domain)
    {
        if ($email_server = $domain->email_server) {
            $email_server = new OrgServerService($email_server);
        } else {
            $email_server = OrgServerService::add($domain->organization, 'email', $domain->organization->plan);
            $domain->email_server_id = $email_server->id;
            $domain->save();
        }

        return $email_server;
    }

    public function serverConnectionInfo(OrgDomain $domain, $server_type)
    {
        // If domain is for an app, use the parent domain's server info.
        // In the future, if I want to split up the apps into different web servers, I should just need to remove this condition and add column to app_instance_domains
        if ($domain->parent_domain && $domain->parent_domain->$server_type()) {
            $server_name = $domain->parent_domain->$server_type()->server_name;
        } elseif ($domain && $domain->$server_type()) {
            $server_name = $domain->$server_type()->server_name;
        }

        $default_server = "default_{$server_type}_server";

        return Server::where($default_server, 1)->first();
    }

    public function ipPointsToServer(OrgSubdomain $domain, Server $server)
    {
        return gethostbyname($domain->name) === $server->ip;
    }

    public function connect(OrgDomain $domain, $type, ?Plan $plan = null)
    {
        $server_type = $type.'_server';
        $server = $domain->$server_type;
        if (! $server) {
            $org_server = OrgServerService::add($domain->organization, 'email', $plan);
            $server = $org_server->org_server;

            $domain->$server_type()->associate($server);
            $domain->save();
        }

        return ServerInterface::connect($server);
    }

    public function isIntegratedRegistrar(OrgDomain $domain)
    {
        return $domain->type === 'managed' && Arr::has($this->drivers, $domain->source);
    }

    public function getTld(string $domain_name)
    {
        $domain_parts = explode('.', $domain_name);

        $double_tlds = ['uk', 'au', 'es', 'pe'];

        $end = end($domain_parts);

        if (in_array($end, $double_tlds)) {
            $tld = prev($domain_parts).'.'.$end;
        } else {
            $tld = $end;
        }

        return $tld;
    }

    public function addSubdomain(string $host, OrgDomain $domain, ?AppInstance $app_instance = null)
    {
        $subdomain = new OrgSubdomain;
        $subdomain->organization()->associate($domain->organization);
        $subdomain->name = $host.'.'.$domain->name;
        $subdomain->status = $domain->status;
        $subdomain->host = $host;

        if ($domain) {
            $subdomain->domain()->associate($domain->id);
        }
        if ($app_instance) {
            $subdomain->app_instance()->associate($app_instance->id);
            $subdomain->value = $app_instance->web_server?->server->ip;
            $subdomain->type = 'app';
        } else {
            $subdomain->type = 'none';
        }
        if ($this->isIntegratedRegistrar($domain)) {
            $subdomain->ttl = 1800;
        }
        $subdomain->save();

        return $subdomain;
    }

    public function updateAppInstance(OrgSubdomain $subdomain, ?AppInstance $app_instance = null)
    {
        $changed = false;
        if ($app_instance && ! $app_instance->is($subdomain->app_instance)) {
            if ($subdomain->type == 'app' && $app_instance && ! $app_instance->is($subdomain->app_instance)) {
                $subdomain->app_instance()->associate($app_instance);
                $subdomain->value = $app_instance->web_server->server->ip;
                $subdomain->save();
                $changed = true;
            } elseif ($app_instance = $subdomain->app_instance) {
                // Gets app_instance so that it can be updated properly
                $subdomain->app_instance()->dissociate();
                $subdomain->value = null;
                $subdomain->save();
                $changed = true;
            }
        }

        if ($changed) {
            Action::execute(new ApplicationUpdate($app_instance));
        }
    }
}
