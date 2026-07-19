<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->string('resource_type')->nullable()->after('category');
            $table->string('resource_name')->nullable()->after('resource_type');
            $table->json('metadata')->nullable()->after('rule_id');
            $table->timestamp('resolved_at')->nullable()->after('metadata');
        });
    }

    public function down()
    {
        Schema::table('security_findings', function (Blueprint $table) {
            $table->dropColumn(['resource_type', 'resource_name', 'metadata', 'resolved_at']);
        });
    }
};
