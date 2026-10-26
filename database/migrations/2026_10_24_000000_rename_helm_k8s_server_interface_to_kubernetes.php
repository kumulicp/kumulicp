<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('servers')->where('interface', 'helm_k8s')->update(['interface' => 'kubernetes']);
    }

    public function down(): void
    {
        DB::table('servers')->where('interface', 'kubernetes')->update(['interface' => 'helm_k8s']);
    }
};
