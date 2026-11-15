<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each app instance last had applied, per aspect (see App\Updates\Aspects).
        // Compared with what the aspect works out now to tell what is out of date.
        Schema::create('update_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_instance_id')->constrained('app_instances')->cascadeOnDelete();
            $table->string('aspect', 100);
            $table->string('fingerprint', 64);
            $table->timestamp('applied_at');
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamps();

            $table->unique(['app_instance_id', 'aspect']);
            $table->index('aspect');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_states');
    }
};
