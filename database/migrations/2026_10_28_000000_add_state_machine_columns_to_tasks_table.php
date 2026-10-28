<?php

use App\Enums\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // When SystemTasks queued the task, so the reaper can tell a lost job from a slow one
            $table->timestamp('queued_at')->nullable()->after('status');
            // Set while a RunAction is running the task
            $table->timestamp('claimed_at')->nullable()->after('queued_at');
            // Past this the reaper rolls the task back and fails it (null: no deadline)
            $table->timestamp('deadline_at')->nullable()->after('claimed_at');

            $table->index('status');
        });

        // Task::$status is cast to TaskStatus, which throws on a value it doesn't
        // know. Nothing writes one today, but a task that can't be loaded can't be
        // retried or removed either, so make sure of it.
        DB::table('tasks')
            ->whereNotIn('status', array_map(fn (TaskStatus $status) => $status->value, TaskStatus::cases()))
            ->update([
                'status' => TaskStatus::FAILED->value,
                'error_message' => 'Task had an unknown status and was marked failed',
            ]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['queued_at', 'claimed_at', 'deadline_at']);
        });
    }
};
