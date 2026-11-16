<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            // Null until the upload is sent with a message (a "draft")
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('ticket_message_id')->nullable();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('uploader_id')->nullable();
            $table->string('disk');
            // Random object name; the original name below is only for display
            $table->string('path');
            $table->string('original_name');
            // Detected from the file contents, never taken from the browser
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('scan_status')->default('skipped');
            // Shown inside the message text rather than in the file list
            $table->boolean('inline')->default(false);
            $table->timestamps();

            $table->index('ticket_message_id');
            $table->index(['ticket_id', 'ticket_message_id']);
            // Finding stale drafts
            $table->index(['uploader_id', 'ticket_message_id', 'created_at'], 'ticket_attachments_drafts');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
