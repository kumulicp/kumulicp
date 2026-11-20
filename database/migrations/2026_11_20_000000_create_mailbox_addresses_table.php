<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every address a mailbox answers to. The mailbox's own address is the primary row; the
     * other rows are aliases (2e). An address is unique across every organization and is
     * stored in lower case, which is what makes the unique index case-insensitive on every
     * database. The table, not a mail server, is how the panel knows what is taken.
     */
    public function up(): void
    {
        Schema::create('mailbox_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mailboxes')->cascadeOnDelete();
            $table->string('address')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['mailbox_id', 'is_primary']);
        });

        // The mailboxes that exist already answer to their own address
        DB::table('mailboxes')->orderBy('id')->chunkById(500, function ($mailboxes) {
            DB::table('mailbox_addresses')->insert($mailboxes->map(fn ($mailbox) => [
                'mailbox_id' => $mailbox->id,
                'address' => strtolower($mailbox->address),
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_addresses');
    }
};
