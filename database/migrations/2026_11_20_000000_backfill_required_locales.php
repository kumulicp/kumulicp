<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Languages can no longer be empty: organizations without a default take the
     * control panel / APP_LOCALE default, and users without one take their organization's.
     */
    public function up()
    {
        $default = DB::table('server_settings')->where('key', 'default_locale')->value('value')
            ?: (config('app.locale') ?: 'en');

        DB::table('organizations')
            ->where(fn ($query) => $query->whereNull('default_locale')->orWhere('default_locale', ''))
            ->update(['default_locale' => $default]);

        DB::table('users')
            ->where(fn ($query) => $query->whereNull('locale')->orWhere('locale', ''))
            ->update([
                'locale' => DB::raw('(select default_locale from organizations where organizations.id = users.organization_id)'),
            ]);

        DB::table('users')
            ->where(fn ($query) => $query->whereNull('locale')->orWhere('locale', ''))
            ->update(['locale' => $default]);
    }

    public function down()
    {
        // Nothing to undo: previously empty values are indistinguishable from chosen ones.
    }
};
