<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which domains an app can be reached at, whether the domain can be changed once it is
     * activated, and the app it is an addon to are chosen per app plan now. Every plan of an
     * app starts with the values the app had, so nothing changes until an admin edits a plan.
     *
     * The user access type of an app is gone: it was only ever the highest of its roles, and
     * the roles set it.
     */
    public function up(): void
    {
        foreach (DB::table('applications')->get() as $application) {
            $domainOption = json_decode((string) $application->domain_option, true);

            $values = [
                'can_update_domain' => (bool) $application->can_update_domain,
                'domain_option' => is_array($domainOption) ? $domainOption : [],
                'parent_app_id' => $application->parent_app_id ?: null,
            ];

            foreach (DB::table('app_plans')->where('application_id', $application->id)->get() as $plan) {
                $settings = json_decode((string) $plan->settings, true);
                $settings = array_merge(is_array($settings) ? $settings : [], $values);

                DB::table('app_plans')->where('id', $plan->id)->update(['settings' => json_encode($settings)]);
            }
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['parent_app_id']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['access_type', 'can_update_domain', 'domain_option', 'parent_app_id']);
        });
    }

    /**
     * Each app takes the settings of its first plan back. The access type is not restored: it
     * was not kept, and the roles hold it.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_app_id')->nullable()->after('category_id');
            $table->string('access_type')->nullable();
            $table->boolean('can_update_domain')->default(0);
            $table->text('domain_option')->nullable();
            $table->index('parent_app_id');
        });

        foreach (DB::table('applications')->get() as $application) {
            $plan = DB::table('app_plans')->where('application_id', $application->id)->orderBy('id')->first();
            $settings = $plan ? (json_decode((string) $plan->settings, true) ?: []) : [];

            DB::table('applications')->where('id', $application->id)->update([
                'parent_app_id' => $settings['parent_app_id'] ?? null,
                'can_update_domain' => (bool) ($settings['can_update_domain'] ?? false),
                'domain_option' => json_encode($settings['domain_option'] ?? []),
            ]);
        }
    }
};
