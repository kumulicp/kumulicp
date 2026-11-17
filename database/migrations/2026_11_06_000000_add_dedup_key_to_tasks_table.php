<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Identifies "the same task" (see Action::dedupKey()); null: never a duplicate
            $table->string('dedup_key', 40)->nullable()->after('task_group');
        });

        // The key while the task is open, null once it is complete or cancelled. A
        // unique index over it lets only one open task exist per key (a failed
        // task is still open, as it always was for deduplication). MySQL has no
        // partial indexes, but it indexes generated columns; NULLs never collide,
        // so finished tasks and tasks without a key are untouched, and no code has
        // to remember to clear a key when a task finishes.
        $open = "CASE WHEN status IN ('complete', 'cancelled') THEN NULL ELSE dedup_key END";

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE tasks ADD COLUMN dedup_open VARCHAR(40) GENERATED ALWAYS AS ({$open}) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX tasks_dedup_open_unique ON tasks (dedup_open)');
        } else {
            DB::statement("ALTER TABLE tasks ADD COLUMN dedup_open VARCHAR(40) GENERATED ALWAYS AS ({$open}) VIRTUAL, ADD UNIQUE INDEX tasks_dedup_open_unique (dedup_open)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX tasks_dedup_open_unique');
        } else {
            DB::statement('ALTER TABLE tasks DROP INDEX tasks_dedup_open_unique');
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['dedup_open', 'dedup_key']);
        });
    }
};
