<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ids a mail server hands back are not always numbers (the LDAP driver answers 'ldap',
     * and other servers use opaque strings), so a customer or domain id of the mail server
     * can't live in an integer column: strict databases refuse the row. Numbers stored so far
     * carry over as they are.
     */
    public function up(): void
    {
        Schema::table('org_domains', function (Blueprint $table) {
            $table->string('email_server_customer_id')->nullable()->change();
            $table->string('email_server_domain_id')->nullable()->change();
        });

        Schema::table('org_servers', function (Blueprint $table) {
            $table->string('server_customer_id')->nullable()->change();
        });
    }

    /**
     * Ids that are not numbers can't go back into an integer column, so they are cleared first.
     */
    public function down(): void
    {
        foreach ([
            'org_domains' => ['email_server_customer_id', 'email_server_domain_id'],
            'org_servers' => ['server_customer_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->get(['id', $column])
                    ->reject(fn ($row) => ctype_digit((string) $row->$column))
                    ->each(fn ($row) => DB::table($table)->where('id', $row->id)->update([$column => null]));
            }
        }

        Schema::table('org_domains', function (Blueprint $table) {
            $table->integer('email_server_customer_id')->nullable()->change();
            $table->integer('email_server_domain_id')->nullable()->change();
        });

        Schema::table('org_servers', function (Blueprint $table) {
            $table->integer('server_customer_id')->nullable()->change();
        });
    }
};
