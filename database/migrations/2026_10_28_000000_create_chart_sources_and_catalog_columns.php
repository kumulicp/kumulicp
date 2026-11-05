<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('url');
            $table->string('type')->default('http');
            $table->unsignedBigInteger('helm_repo_secret_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('chart_source_id')->nullable();
            $table->json('catalog')->nullable();
        });

        DB::table('chart_sources')->insert([
            'name' => 'HelmForge',
            'slug' => 'helmforge',
            'url' => 'https://repo.helmforge.dev',
            'type' => 'http',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['chart_source_id', 'catalog']);
        });

        Schema::dropIfExists('chart_sources');
    }
};
