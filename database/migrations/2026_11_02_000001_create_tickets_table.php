<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            // Sequential number shown to people as PREFIX-number
            $table->unsignedInteger('number')->unique();
            $table->unsignedBigInteger('organization_id');
            // Who opened it
            $table->unsignedBigInteger('requester_id')->nullable();
            // Org member responsible on the customer side
            $table->unsignedBigInteger('customer_assignee_id')->nullable();
            // Staff member handling it
            $table->unsignedBigInteger('support_assignee_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('app_instance_id')->nullable();
            $table->string('subject');
            $table->string('type')->default('question');
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->string('impact')->default('minor');
            $table->string('source')->default('web');
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_requester_reply_at')->nullable();
            $table->timestamp('last_staff_reply_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Optimistic concurrency for staff edits
            $table->unsignedInteger('lock_version')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'last_activity_at'], 'tickets_org_status_activity');
            $table->index(['support_assignee_id', 'status', 'last_activity_at'], 'tickets_support_assignee_status');
            $table->index(['customer_assignee_id', 'status', 'last_activity_at'], 'tickets_customer_assignee_status');
            $table->index('category_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tickets');
    }
};
