<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConvertDomainOptionToArray extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $legacyMap = [
            'none' => [],
            'all' => ['base', 'subdomains', 'primary'],
            'subdomains' => ['subdomains'],
            'primary' => ['primary'],
            'base' => ['base'],
            'parent' => ['parent'],
        ];

        foreach (DB::table('applications')->select('id', 'domain_option')->get() as $app) {
            $value = $legacyMap[$app->domain_option] ?? [];
            DB::table('applications')->where('id', $app->id)->update([
                'domain_option' => json_encode($value),
            ]);
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->text('domain_option')->nullable(false)->change();
        });

        DB::statement("UPDATE `applications` SET `domain_option` = '[]' WHERE `domain_option` IS NULL");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('domain_option')->default('none')->change();
        });

        foreach (DB::table('applications')->select('id', 'domain_option')->get() as $app) {
            $types = json_decode($app->domain_option, true) ?? [];
            $value = 'none';
            if (in_array('parent', $types)) {
                $value = 'parent';
            } elseif (count($types) >= 3) {
                $value = 'all';
            } elseif (in_array('subdomains', $types)) {
                $value = 'subdomains';
            } elseif (in_array('primary', $types)) {
                $value = 'primary';
            } elseif (in_array('base', $types)) {
                $value = 'base';
            }
            DB::table('applications')->where('id', $app->id)->update([
                'domain_option' => $value,
            ]);
        }
    }
}
