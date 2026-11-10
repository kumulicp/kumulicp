<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Edits to the definitions app instances depend on (plans, versions, roles)
        // that could change what an instance should look like. Cosmetic edits and
        // edits nothing uses aren't recorded.
        Schema::create('definition_changes', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // What the subject was called then, in case it is renamed or deleted later
            $table->string('subject_label');
            $table->unsignedBigInteger('application_id')->nullable();
            $table->string('action', 20);
            // The attributes that changed
            $table->json('changed');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('application_id');
        });

        Schema::table('update_states', function (Blueprint $table) {
            // What the aspect worked out the last time it was looked at. Differs from
            // fingerprint (what was applied) while the instance is out of date.
            $table->string('current_fingerprint', 64)->nullable()->after('fingerprint');
            // When it was first seen to be out of date, and the change that did it
            $table->timestamp('drift_detected_at')->nullable()->after('applied_at');
            $table->unsignedBigInteger('definition_change_id')->nullable()->after('drift_detected_at');

            $table->index(['aspect', 'drift_detected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('update_states', function (Blueprint $table) {
            $table->dropIndex(['aspect', 'drift_detected_at']);
            $table->dropColumn(['current_fingerprint', 'drift_detected_at', 'definition_change_id']);
        });

        Schema::dropIfExists('definition_changes');
    }
};
