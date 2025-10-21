<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('amember-sso.tables.subscriptions', 'amember_subscriptions'), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('access_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedInteger('product_id')->index();
            $table->timestamp('begin_date')->nullable();
            $table->timestamp('expire_date')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'product_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('amember-sso.tables.subscriptions', 'amember_subscriptions'));
    }
};
