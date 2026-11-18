<?php

namespace Tests\Feature\Email;

use App\Integrations\AccountManagers\Ldap\User as LdapAccountUser;
use App\Ldap\Actions\Dn;
use App\Ldap\Models\User as LdapUser;
use App\Support\Facades\Settings;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class LdapUserAttributeTest extends TestCase
{
    use RefreshDatabase;

    private LdapAccountUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ldap.connections.default.base_dn' => 'dc=example,dc=com']);
        (new TestSupports)->seed();
        $this->actingAs(User::where('username', 'demo')->firstOrFail());

        // An entry held in memory, as the directory would hand it back
        $entry = new LdapUser;
        $entry->setDn(Dn::create('demo', 'users', 'jane'));
        $entry->setAttribute('cn', 'jane');
        $entry->setAttribute('mail', ['jane@example.com', 'jane@example.org']);
        $entry->setAttribute('telephoneNumber', ['111', '222']);

        $this->user = new LdapAccountUser($entry);
    }

    public function test_the_array_type_returns_every_value_of_the_mapped_attribute()
    {
        $this->assertEqualsCanonicalizing(['jane@example.com', 'jane@example.org'], $this->user->attribute('email', 'array'));
    }

    public function test_the_array_type_returns_an_empty_list_when_the_attribute_is_missing()
    {
        Settings::update('ldap_phone_number', 'noSuchAttribute');

        $this->assertSame([], $this->user->attribute('phone_number', 'array'));
    }

    public function test_the_default_type_returns_only_the_first_value()
    {
        $this->assertContains($this->user->attribute('email'), ['jane@example.com', 'jane@example.org']);
        $this->assertContains($this->user->attribute('phone_number'), ['111', '222']);
    }
}
