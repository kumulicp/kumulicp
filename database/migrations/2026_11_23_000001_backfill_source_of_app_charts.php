<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every chart so far was deployed the way its app is, so it takes the app's source.
     */
    public function up(): void
    {
        DB::statement('UPDATE app_charts SET source = (SELECT applications.source FROM applications WHERE applications.id = app_charts.application_id)');
    }

    public function down(): void
    {
        DB::table('app_charts')->update(['source' => 'generic']);
    }
};
