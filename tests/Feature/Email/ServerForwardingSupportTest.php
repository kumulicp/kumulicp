<?php

namespace Tests\Feature\Email;

use App\Server;
use App\Support\Facades\ServerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerForwardingSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_docker_mail_server_says_it_does_not_forward_without_connecting()
    {
        $server = Server::factory()->create(['type' => 'email', 'interface' => 'ldap']);

        $this->assertFalse(ServerInterface::supportsForwarders($server));
    }

    public function test_a_domain_with_no_mail_server_or_an_interface_that_never_says_is_assumed_to_forward()
    {
        $this->assertTrue(ServerInterface::supportsForwarders(null));
        $this->assertTrue(ServerInterface::supportsForwarders(Server::factory()->create(['type' => 'email', 'interface' => 'not-installed'])));
    }
}
