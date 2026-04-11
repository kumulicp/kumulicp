<?php

namespace App\Services;

use App\AppInstance;
use App\Organization;
use App\OrgServer;
use App\Support\Facades\ServerInterface;

class OrgServerService
{
    public function __construct(public OrgServer $org_server) {}

    public function addBackupServer()
    {
        $server = $this->org_server->server;
        if (! $server->default_backup_server_id) {
            return $this;
        }

        if ($server->default_backup_server_id === $server->id) {
            $this->org_server->backup_server_id = $this->org_server->id;
            $this->org_server->save();

            return $this;
        }

        $backup_server = new OrgServer;
        $backup_server->organization_id = $this->org_server->organization_id;
        $backup_server->server_id = $server->default_backup_server_id;
        $backup_server->save();

        $backup_server->backup_server_id = $backup_server->id;
        $backup_server->save();

        return new self($backup_server);
    }

    public function backupServer()
    {
        $backup_server = null;

        if (! $this->get()->backup_server_id) {
            $backup_server = $this->addBackupServer();

            if ($backup_server) {
                $this->org_server->backup_server()->associate($backup_server->get());
                $this->org_server->save();
            }
        } elseif ($this->org_server->id === $this->org_server->backup_server_id) {
            $backup_server = new OrgServerService($this->org_server);
        } else {
            $backup_server = new OrgServerService($this->org_server->backup_server);
        }

        return $backup_server;
    }

    public function get()
    {
        return $this->org_server;
    }

    public function appInstanceServer(AppInstance &$app_instance, $type)
    {
        $server_type = $type.'_server';
        if (! $org_server = $app_instance->$server_type) {
            $server = $this->defaultServer($type);
            $org_server = OrgServer::where('organization_id', $app_instance->organization_id)->where('server_id', $server->id)->first();

            if (! $org_server) {
                $org_server = $this->addOrgServer($app_instance->organization, 'database');
            }

            $server_type_id = $server_type.'_id';

            $app_instance->$server_type_id = $org_server->id;
            $app_instance->save();
        }

        return $org_server;
    }

    public static function add(Organization $organization, $type, $plan = null)
    {
        $server = null;
        $new_org_server = null;

        $server_id = $type.'_server';
        if (isset($plan->$server_id)) {
            $server = $plan->$server_id;
        } elseif ($type === 'email') {
            $server = $organization->plan->email_server;
        }

        if ($server) {
            $org_server = OrgServer::where('organization_id', $organization->id)->where('server_id', $server->id)->first();
            if (! $org_server) {
                $org_server = new OrgServer;
                $org_server->organization_id = $organization->id;
                $org_server->server_id = $server->id;
                $org_server->save();
                $new_org_server = new self($org_server);

                $backup_server = $new_org_server->backupServer();
            }

            return $new_org_server ?? new self($org_server);
        }

        return null;
    }

    public function serverInfo()
    {
        return $this->org_server->server;
    }

    public function connect()
    {
        return ServerInterface::connect($this->org_server);

        throw new \Exception(__('messages.exception.no_server_interface', ['server' => $server->name]));
    }

    public function checkConnection($connection)
    {
        for ($n = 0; $n < 5; $n++) {
            // If 5 tries, give up
            if ($n == 4) {
                return false;
            }
            // If organization doesn't exist, add
            elseif ($connection->existsOrganization()) {
                return true;
            } else {
                // If first try, add organization, otherwise sleep 5 seconds and try again. Some servers take a few seconds to setup
                if ($n == 0) {
                    $response = $connection->addOrganization();
                }
                sleep(5);
            }
        }

        return true;
    }

    public function __get($property)
    {
        return $this->org_server->$property;
    }

    public function __call($method, $args)
    {
        return $this->org_server->$method(...$args);
    }

    public function __set($property, $value)
    {
        $this->org_server->$property = $value;
    }
}
