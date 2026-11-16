<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registering, transferring and renewing domains became the "domain-registrar"
     * feature, off by default. An install that was already using a registrar keeps it
     * on, so upgrading does not take the feature away. A fresh install, or one that
     * only connects domains, starts with it off.
     */
    public function up(): void
    {
        $inUse = filled(config('domains.default'))
            || DB::table('tlds')->exists()
            || DB::table('org_domains')->where('type', 'managed')->exists();

        if (! $inUse || DB::table('toggles')->where('name', 'domain-registrar')->exists()) {
            return;
        }

        DB::table('toggles')->insert([
            'name' => 'domain-registrar',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The toggle is left as it is: it is only a saved setting.
     */
    public function down(): void
    {
        //
    }
};
