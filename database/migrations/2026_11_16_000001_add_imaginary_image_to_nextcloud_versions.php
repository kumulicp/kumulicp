<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Nextcloud's imaginary image has been set by the imaginary-image-* configurations,
// which neither update checks nor version tests look at. Each Nextcloud version now
// carries it as an image of its own, with the values those configurations default to,
// so the version renders as it did.
return new class extends Migration
{
    public function up(): void
    {
        $application_ids = DB::table('applications')->where('slug', 'nextcloud')->pluck('id');

        if ($application_ids->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('app_versions')
            ->whereIn('application_id', $application_ids)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('app_version_images')
                    ->whereColumn('app_version_images.app_version_id', 'app_versions.id')
                    ->where('app_version_images.key', 'imaginary');
            })
            ->orderBy('id')
            ->select('app_versions.id')
            ->chunkById(200, function ($versions) use ($now) {
                DB::table('app_version_images')->insert($versions->map(fn ($version) => [
                    'app_version_id' => $version->id,
                    'key' => 'imaginary',
                    'label' => 'Imaginary',
                    'role' => 'component',
                    'source' => 'override',
                    'registry' => 'docker.io',
                    'repository' => 'nextcloud/aio-imaginary',
                    'tag' => '20261109_101109',
                    'values_path' => 'imaginary.image',
                    'tag_from_version_name' => false,
                    'update_policy' => 'track',
                    'tag_pattern' => '^\d{8}_\d{6}$',
                    'position' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        DB::table('app_version_images')->where('key', 'imaginary')->where('values_path', 'imaginary.image')->delete();
    }
};
