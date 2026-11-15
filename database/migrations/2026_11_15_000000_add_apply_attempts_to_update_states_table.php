<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_states', function (Blueprint $table) {
            // How many times an update was started on its own for what is out of date, and when
            // it last was. An update that keeps failing isn't started again for ever. Cleared
            // when a step brings the aspect up to date.
            $table->unsignedTinyInteger('apply_attempts')->default(0)->after('dismissed_by_id');
            $table->timestamp('apply_attempted_at')->nullable()->after('apply_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('update_states', function (Blueprint $table) {
            $table->dropColumn(['apply_attempts', 'apply_attempted_at']);
        });
    }
};
