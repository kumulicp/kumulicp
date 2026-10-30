<?php

use App\Support\ConvertLegacyDependencies;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            // A task that is deleted counts as done: ApplicationDelete deletes its
            // own task to say it finished, so whatever waited on it is released.
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            // start: must be complete before the task runs. finish: the task is
            // running but can't complete until it is.
            $table->string('gate', 10)->default('start');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'depends_on_task_id', 'gate']);
            $table->index('depends_on_task_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Whether a blocked task was waiting to start or already running
            $table->string('blocked_from', 20)->nullable()->after('next_attempt_at');
        });

        // Tasks in flight carry their dependencies in the old formats
        (new ConvertLegacyDependencies)->convert();
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('blocked_from');
        });

        Schema::dropIfExists('task_dependencies');
    }
};
