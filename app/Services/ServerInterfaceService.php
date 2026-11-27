<?php

namespace App\Services;

use App\Integrations\ServerManagers\AppDatabase\AppDatabaseProfile;
use App\Integrations\ServerManagers\DockerMailServer\MailserverProfile;
use App\Integrations\ServerManagers\Kubernetes\KubernetesProfile;
use App\Integrations\ServerManagers\Rancher\RancherProfile;
use App\Integrations\SSO\Authentik\AuthentikProfile;
use App\OrgServer;
use App\Server;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ServerInterfaceService
{
    private $interfaces = [
        'web' => [
            'rancher' => RancherProfile::class,
            'kubernetes' => KubernetesProfile::class,
        ],
        'database' => [
            'app_database' => AppDatabaseProfile::class,
        ],
        'sso' => [
            'authentik' => AuthentikProfile::class,
        ],
        'email' => [
            'ldap' => MailserverProfile::class,
        ],
    ];

    public function all($type)
    {
        $interfaces = [];

        foreach (Arr::get($this->interfaces, $type, []) as $name => $interface) {
            $interfaces[] = $name;
        }

        return $interfaces;
    }

    public function get()
    {
        return $this;
    }

    public function register($type, $name, $interface)
    {
        $interface_name = "$type.$name";

        if (class_exists($interface)) {
            Arr::set($this->interfaces, $interface_name, $interface);
        }
    }

    public function profile(Server $server)
    {
        $type = $server->type;
        $name = $server->interface;
        $interface = Arr::get($this->interfaces, "$type.$name");

        if (class_exists($interface)) {
            return new $interface($server);
        }

        throw new \Exception(__('messages.exception.no_server_interface', ['server' => $server->name]));
    }

    public function connect(OrgServer $org_server, ...$object)
    {
        $interface = $this->profile($org_server->server)->interface($org_server->server->type);
        if (class_exists($interface)) {
            $connection = new $interface($org_server, ...$object);

            if ($this->checkConnection($connection)) {
                return $connection;
            }

            throw new \Exception(__('messages.exception.no_connection', ['server' => $org_server->server->name]));
        }

        throw new \Exception(__('messages.exception.no_server_interface', ['server' => $org_server->server->name]));
    }

    /**
     * Whether the mail server keeps a mailbox's aliases itself: its interface has a static `supportsAliases()` that
     * says yes. It is asked of the class, so there is no connection to the server and a page can ask it freely.
     */
    public function supportsAliases(OrgServer|Server|null $server): bool
    {
        $server = $server instanceof OrgServer ? $server->server : $server;

        if (! $server) {
            return false;
        }

        try {
            $interface = $this->profile($server)->interface($server->type);
        } catch (\Throwable) {
            return false;
        }

        return is_string($interface) && class_exists($interface)
            && method_exists($interface, 'supportsAliases') && $interface::supportsAliases() === true;
    }

    /**
     * Whether the mail server forwards: its interface has a `supportsForwarders()`, which says no when it can't. Asked of
     * the class, so there is no connection to the server and a page can ask it freely. An interface that never says is
     * assumed to, as is a domain with no mail server of its own to ask.
     */
    public function supportsForwarders(OrgServer|Server|null $server): bool
    {
        $server = $server instanceof OrgServer ? $server->server : $server;

        if (! $server) {
            return true;
        }

        try {
            $interface = $this->profile($server)->interface($server->type);
        } catch (\Throwable) {
            return true;
        }

        if (! is_string($interface) || ! class_exists($interface) || ! method_exists($interface, 'supportsForwarders')) {
            return true;
        }

        return (new \ReflectionMethod($interface, 'supportsForwarders'))->isStatic() ? $interface::supportsForwarders() : true;
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

                    if (is_array($response) && ($response['status'] ?? null) === 'failed') {
                        Log::warning('Could not add the organization', ['connection' => $connection::class, 'response' => $response['response'] ?? null]);
                    }
                }
                sleep(5);
            }
        }

        return true;
    }
}
