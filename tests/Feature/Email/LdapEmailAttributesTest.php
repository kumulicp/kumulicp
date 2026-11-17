<?php

namespace Tests\Feature\Email;

use App\Support\Email\LdapEmailAttributes;
use App\Support\Facades\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LdapEmailAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_aliases_are_enabled_without_ldap_whatever_the_settings_say()
    {
        config(['account_manager.driver' => 'db']);

        $this->assertFalse(LdapEmailAttributes::usingLdap());
        $this->assertTrue(LdapEmailAttributes::aliasesEnabled());
    }

    public function test_aliases_are_disabled_with_ldap_until_an_attribute_is_set()
    {
        config(['account_manager.driver' => 'ldap']);

        $this->assertFalse(LdapEmailAttributes::aliasesEnabled());

        Settings::update('ldap_aliases', 'proxyAddresses');

        $this->assertTrue(LdapEmailAttributes::aliasesEnabled());
        $this->assertSame('proxyAddresses', LdapEmailAttributes::aliasAttribute());
    }

    public function test_a_blank_attribute_counts_as_not_set()
    {
        config(['account_manager.driver' => 'ldap']);
        Settings::update('ldap_aliases', '   ');

        $this->assertNull(LdapEmailAttributes::aliasAttribute());
        $this->assertFalse(LdapEmailAttributes::aliasesEnabled());
    }

    public function test_recovery_email_only_syncs_with_ldap_and_an_attribute()
    {
        Settings::update('ldap_recovery_email', 'recoveryMail');

        config(['account_manager.driver' => 'db']);
        $this->assertFalse(LdapEmailAttributes::recoverySyncEnabled());

        config(['account_manager.driver' => 'ldap']);
        $this->assertTrue(LdapEmailAttributes::recoverySyncEnabled());

        Settings::update('ldap_recovery_email', null);
        $this->assertFalse(LdapEmailAttributes::recoverySyncEnabled());
    }

    public function test_mapping_falls_back_to_the_default_attribute_for_blank_fields()
    {
        $mapping = LdapEmailAttributes::mapping(['first_name' => 'gn', 'last_name' => '  ']);

        $this->assertSame('gn', $mapping['first_name']);
        $this->assertSame('sn', $mapping['last_name']);
        $this->assertSame('mail', $mapping['personal_email']);
    }

    public function test_an_attribute_no_other_mapping_uses_has_no_conflict()
    {
        $this->assertSame([], LdapEmailAttributes::conflicts([
            'aliases' => 'proxyAddresses',
            'recovery_email' => 'recoveryMail',
        ]));
    }

    public function test_an_attribute_left_blank_never_conflicts()
    {
        $this->assertSame([], LdapEmailAttributes::conflicts(['aliases' => '', 'recovery_email' => null]));
    }

    public function test_the_default_mail_attribute_conflicts_even_when_it_was_not_typed_in()
    {
        $conflicts = LdapEmailAttributes::conflicts(['aliases' => 'mail']);

        $this->assertArrayHasKey('aliases', $conflicts);
        $this->assertSame('email', $conflicts['aliases']);
    }

    public function test_conflicts_ignore_the_case_of_the_attribute_name()
    {
        $conflicts = LdapEmailAttributes::conflicts(['recovery_email' => 'EmployeeType']);

        $this->assertSame(['recovery_email' => 'access_type'], $conflicts);
    }

    public function test_an_attribute_set_by_the_admin_conflicts_with_the_new_ones()
    {
        $conflicts = LdapEmailAttributes::conflicts([
            'org_email' => 'organizationMail',
            'aliases' => 'organizationMail',
        ]);

        $this->assertSame(['aliases' => 'org_email'], $conflicts);
    }

    public function test_aliases_and_the_recovery_email_cannot_share_an_attribute()
    {
        $conflicts = LdapEmailAttributes::conflicts([
            'aliases' => 'extraMail',
            'recovery_email' => 'extraMail',
        ]);

        // Each is reported against the other, so both fields show the error
        $this->assertSame(['aliases' => 'recovery_email', 'recovery_email' => 'aliases'], $conflicts);
    }
}
