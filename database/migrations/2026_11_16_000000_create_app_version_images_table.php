<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_version_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_version_id')->constrained('app_versions')->cascadeOnDelete();
            // Stable within a version and across versions: 'primary', 'synapse', 'redis' ...
            $table->string('key', 64);
            $table->string('label')->nullable();
            // primary, component or job
            $table->string('role', 20)->default('component');
            // override: Kumulicp writes it into the chart's values.
            // chart: the chart's own default, recorded as inventory only.
            $table->string('source', 20)->default('override');
            $table->string('registry')->nullable();
            $table->string('repository');
            $table->string('tag')->nullable();
            $table->string('digest')->nullable();
            $table->foreignId('registry_secret_id')->nullable()->constrained('repo_secrets')->nullOnDelete();
            // Where it goes in the chart's values ('image' writes image.registry, .repository, .tag ...)
            $table->string('values_path')->default('image');
            // The version's name is this image's tag (how single-image apps have always worked)
            $table->boolean('tag_from_version_name')->default(false);
            // track, patch, minor, pinned or ignore
            $table->string('update_policy', 20)->default('track');
            $table->string('tag_pattern')->nullable();
            $table->string('group')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['app_version_id', 'key']);
        });

        $this->backfill();
    }

    // Every version that has an image gets a primary image row made from the
    // settings it has always used, so reading the new table agrees with the old.
    private function backfill(): void
    {
        DB::table('app_versions')->orderBy('id')->chunkById(200, function ($versions) {
            $now = now();
            $rows = [];

            foreach ($versions as $version) {
                $settings = is_string($version->settings) ? json_decode($version->settings, true) : $version->settings;
                $repository = is_array($settings) ? ($settings['image_repo_name'] ?? null) : null;

                if (! is_string($repository) || trim($repository) === '') {
                    continue;
                }

                $rows[] = [
                    'app_version_id' => $version->id,
                    'key' => 'primary',
                    'role' => 'primary',
                    'source' => 'override',
                    'registry' => $settings['image_registry'] ?? null,
                    'repository' => $repository,
                    'tag' => $version->name,
                    'registry_secret_id' => $version->image_registry_id,
                    'values_path' => 'image',
                    'tag_from_version_name' => true,
                    'update_policy' => 'track',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows) {
                DB::table('app_version_images')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_version_images');
    }
};
