<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('app_version_id')->constrained('app_versions')->cascadeOnDelete();
            // The enabled version when the run started; upgrade steps start from it
            $table->unsignedBigInteger('baseline_version_id')->nullable();
            $table->string('status', 20)->default('running');
            // Hash of the version and plans as they were tested. When it no longer
            // matches, the run stops counting towards enabling the version.
            $table->string('fingerprint', 64);
            $table->unsignedBigInteger('started_by_id')->nullable();
            $table->text('bypass_reason')->nullable();
            $table->unsignedBigInteger('bypassed_by_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['app_version_id', 'status']);
            $table->index('status');
        });

        Schema::create('version_test_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_test_run_id')->constrained('version_test_runs')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type', 20);
            $table->unsignedBigInteger('app_plan_id');
            // Upgrade steps install this version first, then upgrade to the tested one
            $table->unsignedBigInteger('from_version_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('account_test_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->string('app_url')->nullable();
            $table->string('test_username')->nullable();
            $table->text('test_password')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by_id')->nullable();
            $table->timestamps();

            $table->index(['version_test_run_id', 'position']);
            $table->index('status');
        });

        Schema::create('version_test_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_test_run_id')->constrained('version_test_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('version_test_step_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('version_test_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_test_events');
        Schema::dropIfExists('version_test_steps');
        Schema::dropIfExists('version_test_runs');
    }
};
