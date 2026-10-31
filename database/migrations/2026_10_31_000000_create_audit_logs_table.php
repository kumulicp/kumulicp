<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (see App\AuditLog). Deliberately has no foreign keys:
     * entries must outlive the users, organizations and records they describe.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->dateTime('occurred_at', 6)->index();

            $table->string('event', 100)->index();
            $table->string('outcome', 16)->default('success');

            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->unsignedBigInteger('actor_organization_id')->nullable();
            $table->unsignedBigInteger('target_organization_id')->nullable()->index();

            $table->string('subject_type')->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->string('subject_label')->nullable();

            $table->json('diff')->nullable();
            $table->json('properties')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('route')->nullable();
            $table->string('source', 16)->default('web');
            $table->unsignedBigInteger('task_id')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
