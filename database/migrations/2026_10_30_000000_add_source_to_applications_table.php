<?php

use App\Enums\ApplicationSource;
use App\Services\ApplicationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('source')->default(ApplicationSource::Generic->value)->after('slug');
        });

        // Catalog apps came from HelmForge, the only chart source so far. Of
        // the rest, those with a registered profile (built-in or plugin) are
        // `profile` apps and the others were added through the UI.
        $registered = app(ApplicationService::class);

        DB::table('applications')->orderBy('id')->each(function ($application) use ($registered) {
            $source = match (true) {
                $application->catalog !== null => ApplicationSource::HelmForge,
                $registered->isRegistered($application->slug) => ApplicationSource::Profile,
                default => ApplicationSource::Generic,
            };

            DB::table('applications')->where('id', $application->id)->update(['source' => $source->value]);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
