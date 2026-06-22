<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_assignments', function (Blueprint $table) {
            $table->id();
            $table->morphs('assignable');
            $table->string('server_type');
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assignable_type', 'assignable_id', 'server_type'], 'server_assignments_assignable_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_assignments');
    }
};
