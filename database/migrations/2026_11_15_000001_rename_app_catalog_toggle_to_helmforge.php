<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The catalog sync flag became the HelmForge feature on the Features page
    public function up(): void
    {
        if (! DB::table('toggles')->where('name', 'helmforge')->exists()) {
            DB::table('toggles')->where('name', 'app-catalog')->update(['name' => 'helmforge']);
        }

        DB::table('toggles')->where('name', 'app-catalog')->delete();
    }

    public function down(): void
    {
        DB::table('toggles')->where('name', 'helmforge')->update(['name' => 'app-catalog']);
    }
};
