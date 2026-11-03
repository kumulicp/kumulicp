<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ticket_sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            // Used for tickets whose category has no policy of its own
            $table->boolean('is_default')->default(false);
            // {low: {first_response: minutes, resolution: minutes}, normal: ..., high: ..., urgent: ...}
            $table->json('targets');
            // Null = the clock runs around the clock.
            // {timezone, days: [1..7 ISO weekdays], start: "09:00", end: "17:00", holidays: ["2026-12-25"]}
            $table->json('business_hours')->nullable();
            $table->timestamps();
        });

        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_policy_id')->nullable();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_policy_id')->nullable();
            $table->timestamp('sla_first_response_due_at')->nullable();
            $table->timestamp('sla_resolution_due_at')->nullable();
            $table->timestamp('sla_first_response_warned_at')->nullable();
            $table->timestamp('sla_resolution_warned_at')->nullable();
            $table->timestamp('sla_first_response_breached_at')->nullable();
            $table->timestamp('sla_resolution_breached_at')->nullable();
            // While waiting on the customer or on hold the resolution clock stops
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            // Automation
            $table->timestamp('reminder_sent_at')->nullable();
            // Customer satisfaction
            $table->unsignedTinyInteger('csat_score')->nullable();
            $table->text('csat_comment')->nullable();
            $table->timestamp('csat_at')->nullable();
            // Set when this ticket was merged into another
            $table->unsignedBigInteger('merged_into_id')->nullable();

            $table->index('merged_into_id');
            $table->index(['sla_first_response_due_at', 'sla_first_response_breached_at'], 'tickets_sla_first');
            $table->index(['sla_resolution_due_at', 'sla_resolution_breached_at'], 'tickets_sla_resolution');
        });

        Schema::create('ticket_canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body_html');
            // shared = every agent; personal = only its owner
            $table->string('scope')->default('shared');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->index(['scope', 'owner_id']);
        });

        // Remembers what an outside system has already told us, so a repeated call does nothing
        Schema::create('ticket_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('integration_id');
            $table->string('event_key');
            $table->timestamp('created_at')->nullable();

            $table->unique(['integration_id', 'event_key']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ticket_inbound_events');
        Schema::dropIfExists('ticket_canned_responses');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_sla_first');
            $table->dropIndex('tickets_sla_resolution');
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn([
                'sla_policy_id', 'sla_first_response_due_at', 'sla_resolution_due_at', 'sla_first_response_warned_at',
                'sla_resolution_warned_at', 'sla_first_response_breached_at', 'sla_resolution_breached_at', 'sla_paused_at',
                'sla_paused_minutes', 'reminder_sent_at', 'csat_score', 'csat_comment', 'csat_at', 'merged_into_id',
            ]);
        });

        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn('sla_policy_id');
        });

        Schema::dropIfExists('ticket_sla_policies');
    }
};
