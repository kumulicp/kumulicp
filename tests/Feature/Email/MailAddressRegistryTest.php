<?php

namespace Tests\Feature\Email;

use App\Mailbox;
use App\Services\Email\CompositeMailAddressRegistry;
use App\Services\Email\DatabaseMailAddressRegistry;
use App\Services\Email\MailAddressRegistry;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Email\FakeMailAddressRegistry;
use Tests\Support\TestSupports;
use Tests\TestCase;

class MailAddressRegistryTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseMailAddressRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        (new TestSupports)->seed();
        $this->registry = new DatabaseMailAddressRegistry;
    }

    public function test_an_address_that_belongs_to_a_mailbox_is_taken_whatever_its_case()
    {
        Mailbox::create(['organization_id' => 1, 'local_part' => 'jane', 'address' => 'jane@example.org']);

        $this->assertTrue($this->registry->inUse('Jane@Example.org'));
        $this->assertFalse($this->registry->inUse('jo@example.org'));
    }

    public function test_an_address_a_user_signs_in_with_is_taken()
    {
        $this->assertTrue($this->registry->inUse('demo@example.com'));
    }

    public function test_the_address_a_user_signs_in_with_is_not_taken_against_that_user()
    {
        $this->assertFalse($this->registry->inUse('demo@example.com', 'demo'));
        $this->assertTrue($this->registry->inUse('demo@example.com', 'somebody-else'));
    }

    public function test_the_exception_does_not_cover_an_address_that_is_already_a_mailbox()
    {
        Mailbox::create(['organization_id' => 1, 'local_part' => 'demo', 'address' => 'demo@example.com', 'username' => 'demo']);

        $this->assertTrue($this->registry->inUse('demo@example.com', 'demo'));
    }

    public function test_a_deleted_users_address_stays_taken_because_the_database_keeps_it_unique()
    {
        User::where('username', 'demo')->firstOrFail()->delete();

        $this->assertTrue($this->registry->inUse('demo@example.com'));
    }

    public function test_the_composite_asks_every_registry_and_passes_the_user_on()
    {
        $directory = new FakeMailAddressRegistry;
        $directory->taken = ['in.directory@example.org'];
        $directory->owners = ['in.directory@example.org' => 'demo'];
        $composite = new CompositeMailAddressRegistry($this->registry, $directory);

        $this->assertTrue($composite->inUse('in.directory@example.org'));
        $this->assertFalse($composite->inUse('in.directory@example.org', 'demo'));
        $this->assertTrue($composite->inUse('demo@example.com', 'someone'));
        $this->assertFalse($composite->inUse('free@example.org'));
    }

    public function test_the_container_registry_is_the_database_one_without_ldap()
    {
        config(['account_manager.driver' => 'db']);

        $registry = $this->app->make(MailAddressRegistry::class);

        $this->assertInstanceOf(CompositeMailAddressRegistry::class, $registry);
        $this->assertTrue($registry->inUse('demo@example.com'));
    }
}
