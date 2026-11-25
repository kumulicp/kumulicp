<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An upgrade used to leave the steps a new version might need in an app's
     * settings (update.recommended), to tick in its Update list. What an upgrade puts
     * out of date is now kept with the rest of what is out of date, and nothing reads
     * the setting: this takes it out of the apps that still have it.
     */
    public function up(): void
    {
        DB::table('app_instances')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunkById(200, function ($instances) {
                foreach ($instances as $instance) {
                    $settings = json_decode($instance->settings, true);

                    if (! is_array($settings) || ! Arr::has($settings, 'update.recommended')) {
                        continue;
                    }

                    Arr::forget($settings, 'update.recommended');

                    // Nothing else is kept under update
                    if (($settings['update'] ?? null) === []) {
                        unset($settings['update']);
                    }

                    DB::table('app_instances')->where('id', $instance->id)->update(['settings' => json_encode($settings)]);
                }
            });
    }

    // What it held can't be known again, and nothing reads it
    public function down(): void {}
};
