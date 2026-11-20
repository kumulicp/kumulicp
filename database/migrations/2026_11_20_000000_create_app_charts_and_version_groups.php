<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One deployable source of an app: the chart, where it is published and
        // which chart source it was imported from (if any)
        Schema::create('app_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('chart_name')->nullable();
            // A repository URL, an oci:// reference or the name of a Rancher ClusterRepo
            $table->string('helm_repo')->nullable();
            $table->foreignId('helm_repo_secret_id')->nullable()->constrained('repo_secrets')->nullOnDelete();
            $table->foreignId('chart_source_id')->nullable()->constrained('chart_sources')->nullOnDelete();
            // The chart plans use when they pick none
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['application_id', 'slug']);
        });

        // A track of versions of one chart, with an active version of its own
        Schema::create('app_version_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_chart_id')->constrained('app_charts')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            // The group plans use when they pick none
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['app_chart_id', 'slug']);
        });

        Schema::table('app_versions', function (Blueprint $table) {
            $table->foreignId('version_group_id')->nullable()->constrained('app_version_groups')->nullOnDelete();
        });

        // Empty: the plan follows the default group of its app
        Schema::table('app_plans', function (Blueprint $table) {
            $table->foreignId('version_group_id')->nullable()->constrained('app_version_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_group_id');
        });

        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_group_id');
        });

        Schema::dropIfExists('app_version_groups');
        Schema::dropIfExists('app_charts');
    }
};
