<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An app's monitor goes with the app, whichever way the app is deleted.
     *
     * Doing it from AppInstanceObserver missed every delete that skips model
     * events, like DeleteOrganization's `$organization->app_instances()->delete()`,
     * and left monitors for apps that no longer exist. A cascading foreign key
     * can't be bypassed. Monitors added by hand have no app_instance_id and are
     * not touched.
     */
    public function up(): void
    {
        // The key can't be added while a monitor points at an app that is gone
        DB::table('monitors')
            ->whereNotNull('app_instance_id')
            ->whereNotIn('app_instance_id', DB::table('app_instances')->select('id'))
            ->delete();

        Schema::table('monitors', function (Blueprint $table) {
            $table->foreign('app_instance_id')
                ->references('id')
                ->on('app_instances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropForeign(['app_instance_id']);
        });
    }
};
