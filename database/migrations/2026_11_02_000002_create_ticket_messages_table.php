<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('author_id')->nullable();
            // requester (an org member), staff, or system
            $table->string('author_kind')->default('requester');
            // public is visible to the customer; internal is staff only
            $table->string('visibility')->default('public');
            // Sanitized HTML, and the plain text derived from it
            $table->longText('body_html');
            $table->longText('body_text');
            $table->string('source')->default('web');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });

        Schema::create('ticket_message_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_message_id');
            $table->longText('body_html');
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('ticket_message_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_message_revisions');
        Schema::dropIfExists('ticket_messages');
    }
};
