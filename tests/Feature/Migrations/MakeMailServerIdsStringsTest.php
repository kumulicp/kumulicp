<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MakeMailServerIdsStringsTest extends TestCase
{
    use RefreshDatabase;

    private function migration()
    {
        return require database_path('migrations/2026_11_17_100000_make_mail_server_ids_strings.php');
    }

    /**
     * @return array<string, string> The type of each column, by name
     */
    private function types(string $table): array
    {
        return collect(Schema::getColumns($table))->pluck('type_name', 'name')->all();
    }

    public function test_the_mail_server_id_columns_are_text_after_migrating()
    {
        $this->assertStringContainsString('char', $this->types('org_domains')['email_server_customer_id']);
        $this->assertStringContainsString('char', $this->types('org_domains')['email_server_domain_id']);
        $this->assertStringContainsString('char', $this->types('org_servers')['server_customer_id']);
    }

    public function test_the_ids_the_ldap_driver_answers_with_can_be_stored_and_read_back()
    {
        $org_server_id = $this->orgServer('ldap');

        $this->assertSame('ldap', DB::table('org_servers')->where('id', $org_server_id)->value('server_customer_id'));
    }

    public function test_numbers_stored_before_the_change_carry_over_as_they_are()
    {
        $this->migration()->down();
        $org_server_id = $this->orgServer(42);

        $this->migration()->up();

        $this->assertSame('42', (string) DB::table('org_servers')->where('id', $org_server_id)->value('server_customer_id'));
    }

    public function test_rolling_back_clears_ids_that_are_not_numbers_instead_of_failing()
    {
        $text_id = $this->orgServer('ldap');
        $number_id = $this->orgServer('7');

        $this->migration()->down();

        $this->assertNull(DB::table('org_servers')->where('id', $text_id)->value('server_customer_id'));
        $this->assertSame(7, (int) DB::table('org_servers')->where('id', $number_id)->value('server_customer_id'));
        $this->assertStringContainsString('int', $this->types('org_servers')['server_customer_id']);
    }

    private function orgServer(string|int $customer_id): int
    {
        return DB::table('org_servers')->insertGetId([
            'organization_id' => 1,
            'server_id' => 1,
            'server_customer_id' => $customer_id,
        ]);
    }
}
