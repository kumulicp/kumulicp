<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('toggles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        // The feature flags were set with TOGGLE_* env vars until now. Keep the
        // ones that are on, so the env vars can go. A flag that is off needs no
        // row: with none it is off too.
        $now = now();
        $enabled = collect(config('toggle.flags', []))
            ->filter()
            ->map(fn ($value, $name) => [
                'name' => $name,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($enabled !== []) {
            DB::table('toggles')->insert($enabled);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('toggles');
    }
};
