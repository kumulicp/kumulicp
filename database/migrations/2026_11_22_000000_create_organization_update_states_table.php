<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each organization's subscription last had applied, per aspect (see
        // App\Updates\OrganizationDrift): the organization's counterpart of update_states.
        // Compared with what the aspect works out now to tell what is out of date.
        Schema::create('organization_update_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('aspect', 100);
            $table->string('fingerprint', 64);
            // What the aspect worked out the last time it was looked at; differs from
            // fingerprint while the organization is out of date
            $table->string('current_fingerprint', 64)->nullable();
            // What the fingerprint is made of, where it has to be read back (the plans the
            // organization was on, to say which it moved to another from)
            $table->json('details')->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('drift_detected_at')->nullable();
            $table->unsignedBigInteger('definition_change_id')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by_id')->nullable();
            $table->unsignedTinyInteger('apply_attempts')->default(0);
            $table->timestamp('apply_attempted_at')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'aspect']);
            $table->index(['aspect', 'drift_detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_update_states');
    }
};
