<?php

use App\Enums\ApplicationSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog apps came from HelmForge, the only chart source so far
        DB::table('applications')->whereNotNull('catalog')->update(['source' => ApplicationSource::HelmForge->value]);
    }

    public function down(): void
    {
        DB::table('applications')->where('source', ApplicationSource::HelmForge->value)->update(['source' => ApplicationSource::Generic->value]);
    }
};
