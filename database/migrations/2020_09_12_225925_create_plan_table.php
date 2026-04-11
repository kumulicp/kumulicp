    <?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePlanTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_server_id')->nullable();
            $table->integer('display_order')->nullable();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('description');
            $table->string('org_type');
            $table->string('features')->nullable();
            $table->boolean('payment_enabled')->default(0);
            $table->boolean('domain_enabled')->default(0);
            $table->integer('domain_max')->nullable();
            $table->boolean('email_enabled')->default(0);
            $table->boolean('is_default')->default(0);
            $table->boolean('archive')->default(0);
            $table->json('app_plans')->nullable();
            $table->json('settings')->nullable();
            $table->string('status')->default('hidden');
            $table->timestamp('release_date')->nullable();
            $table->timestamp('suppress_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('plans');
    }
}
