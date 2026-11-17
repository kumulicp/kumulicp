<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mailboxes the control panel has given out, whichever mail server holds them.
     *
     * An address is unique across every organization (the unique index is what stops two
     * people being given the same one). A mailbox that belongs to a user is how the panel
     * knows that user's `mail` is an organization address: it is `pending` while only
     * offered, and `active` once the user has accepted it. The previous_* columns are what
     * the user had before the switch, kept so it can be undone for a while.
     */
    public function up(): void
    {
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('org_domain_id')->nullable();
            $table->string('local_part', 64);
            $table->string('address')->unique();
            $table->string('username', 100)->nullable();
            $table->string('display_name')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('swapped_at')->nullable();
            $table->string('previous_mail')->nullable();
            $table->string('previous_recovery_email')->nullable();
            $table->timestamps();
            $table->index('organization_id');
            $table->index('org_domain_id');
            $table->index('username');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailboxes');
    }
};
