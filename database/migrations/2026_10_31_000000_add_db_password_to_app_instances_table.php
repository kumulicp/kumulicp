<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_instances', function (Blueprint $table) {
            $table->text('db_password')->nullable()->after('api_password');
        });

        $organization_passwords = DB::table('organizations')->whereNotNull('secretpw')->pluck('secretpw', 'id');

        DB::table('app_instances')->orderBy('id')->each(function ($app_instance) use ($organization_passwords) {
            $settings = json_decode($app_instance->settings ?? '', true) ?: [];

            // The app's own password wins, the organization's password is what
            // the database user has for apps that never got one.
            $password = ($settings['db_password'] ?? null) ?: ($organization_passwords[$app_instance->organization_id] ?? null);

            if (! $password) {
                return;
            }

            unset($settings['db_password']);

            DB::table('app_instances')->where('id', $app_instance->id)->update([
                'db_password' => $this->encrypted($password),
                'settings' => $settings ? json_encode($settings) : null,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('app_instances')->whereNotNull('db_password')->orderBy('id')->each(function ($app_instance) {
            $settings = json_decode($app_instance->settings ?? '', true) ?: [];
            $settings['db_password'] = $app_instance->db_password;

            DB::table('app_instances')->where('id', $app_instance->id)->update(['settings' => json_encode($settings)]);
        });

        Schema::table('app_instances', function (Blueprint $table) {
            $table->dropColumn('db_password');
        });
    }

    /**
     * Keeps a value that is already encrypted and encrypts a plaintext one.
     */
    private function encrypted(string $value): string
    {
        try {
            Crypt::decryptString($value);

            return $value;
        } catch (DecryptException) {
            return Crypt::encryptString($value);
        }
    }
};
