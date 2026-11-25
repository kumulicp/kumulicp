<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forwarders live in the panel's database, as mailboxes and aliases do. What a forwarder
     * delivers to is a row of its own, its address is a row of `mailbox_addresses` (so the one
     * unique index answers "is this taken" for mailboxes, aliases and forwarders alike), and
     * what the mail server has applied is a status. `server_email_id` was an id from a mail
     * server API that no longer exists: it stops being required, and is dropped once nothing
     * writes it.
     */
    public function up(): void
    {
        Schema::table('email_forwarders', function (Blueprint $table) {
            $table->integer('server_email_id')->nullable()->change();
            $table->string('status', 20)->default('pending')->after('email');
            $table->timestamp('applied_at')->nullable()->after('status');
            $table->text('last_error')->nullable()->after('applied_at');
        });

        Schema::create('email_forwarder_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_forwarder_id')->constrained('email_forwarders')->cascadeOnDelete();
            $table->string('destination');
            $table->string('kind', 20)->default('external');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamps();
            // Named: the generated name is 66 characters and MySQL allows 64
            $table->unique(['email_forwarder_id', 'destination'], 'email_fwd_dest_forwarder_destination_unique');
        });

        Schema::table('mailbox_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('mailbox_id')->nullable()->change();
            $table->foreignId('forwarder_id')->nullable()->after('mailbox_id')->constrained('email_forwarders')->cascadeOnDelete();
        });

        // The forwarders that exist already keep their address. One that collides with an address
        // someone else has is left without a row (it is reported by the forwarders command)
        DB::table('email_forwarders')->orderBy('id')->chunkById(500, function ($forwarders) {
            foreach ($forwarders as $forwarder) {
                DB::table('mailbox_addresses')->insertOrIgnore([
                    'forwarder_id' => $forwarder->id,
                    'address' => strtolower($forwarder->email),
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('mailbox_addresses')->whereNull('mailbox_id')->delete();

        Schema::table('mailbox_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forwarder_id');
            $table->unsignedBigInteger('mailbox_id')->nullable(false)->change();
        });

        Schema::dropIfExists('email_forwarder_destinations');

        Schema::table('email_forwarders', function (Blueprint $table) {
            $table->dropColumn(['status', 'applied_at', 'last_error']);
        });
    }
};
