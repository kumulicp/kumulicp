<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_integrations', function (Blueprint $table) {
            $table->id();
            // Registered driver key: generic_webhook, github, gitlab, ...
            $table->string('driver');
            $table->string('name');
            // Driver settings including secrets; stored encrypted
            $table->longText('config');
            $table->boolean('enabled')->default(true);
            // Only tickets matching these are sent: {types: [], categories: [], priorities: []}; empty = any
            $table->json('filters')->nullable();
            // Event names a generic webhook subscribes to
            $table->json('events')->nullable();
            // Behaviour switches: auto_create, sync_state, include_requester, allow_internal
            $table->json('options')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('disabled_reason')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        // A ticket's issue in an external tracker
        Schema::create('ticket_external_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('integration_id');
            $table->string('external_id');
            // Short human form, e.g. #42
            $table->string('external_key')->nullable();
            $table->string('external_url', 500)->nullable();
            // open or closed, as last pushed
            $table->string('external_state')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ticket_id', 'integration_id', 'external_id'], 'ticket_links_unique');
            $table->index('integration_id');
        });

        // Every attempt to tell an integration about something
        Schema::create('ticket_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->unsignedBigInteger('ticket_id');
            $table->string('event');
            // Stops the same thing being delivered twice
            $table->string('idempotency_key')->unique();
            // pending, delivered, failed (gave up)
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error')->nullable();
            // What is sent, snapshotted when the event happened, with privacy options applied
            $table->json('payload');
            // Redacted and truncated record of the last answer
            $table->text('response')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'ticket_id', 'id'], 'ticket_deliveries_order');
            $table->index(['ticket_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('ticket_time_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('minutes');
            $table->string('note', 500)->nullable();
            $table->boolean('billable')->default(true);
            $table->date('worked_at');
            $table->timestamps();

            $table->index('ticket_id');
            $table->index(['user_id', 'worked_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_time_entries');
        Schema::dropIfExists('ticket_deliveries');
        Schema::dropIfExists('ticket_external_links');
        Schema::dropIfExists('ticket_integrations');
    }
};
