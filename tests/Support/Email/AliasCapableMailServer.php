<?php

namespace Tests\Support\Email;

use App\OrgDomain;
use App\OrgServer;
use App\Server;
use App\Support\Facades\ServerInterface;

/**
 * A mail server interface that keeps a mailbox's aliases itself, as the Stalwart plugin does. It is its own
 * profile as well, so one class is all a test has to register.
 */
class AliasCapableMailServer
{
    public function __construct(public Server|OrgServer|null $server = null) {}

    public static function supportsAliases(): bool
    {
        return true;
    }

    public function interface(string $type): string
    {
        return self::class;
    }

    /**
     * Puts the domain on a server of this kind.
     */
    public static function useFor(OrgDomain $domain): void
    {
        ServerInterface::register('email', 'aliasing', self::class);

        $server = Server::factory()->create(['type' => 'email', 'interface' => 'aliasing']);
        $org_server = OrgServer::create(['organization_id' => $domain->organization_id, 'server_id' => $server->id]);

        $domain->forceFill(['email_server_id' => $org_server->id])->save();
    }
}
