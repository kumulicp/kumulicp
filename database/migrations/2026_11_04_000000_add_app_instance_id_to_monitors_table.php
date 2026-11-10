<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties a monitor to the app instance it watches (null for monitors added
     * by hand). The foreign key that removes a monitor with its app instance is
     * added by cascade_monitors_with_their_app_instance.
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedBigInteger('app_instance_id')->nullable()->after('url')->index();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropIndex(['app_instance_id']);
            $table->dropColumn('app_instance_id');
        });
    }
};
