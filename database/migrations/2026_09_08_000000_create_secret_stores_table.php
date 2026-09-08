<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secret_stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver')->default('database');
            $table->boolean('is_default')->default(false);
            $table->string('address')->nullable();
            $table->text('role_id')->nullable();
            $table->text('secret_id')->nullable();
            $table->string('mount_path')->nullable();
            $table->string('namespace')->nullable();
            $table->text('config')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        DB::table('secret_stores')->insert([
            'name' => 'Local Database',
            'driver' => 'database',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_stores');
    }
};
