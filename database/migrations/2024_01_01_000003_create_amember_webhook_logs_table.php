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
        Schema::create(config('amember-sso.tables.webhook_logs', 'amember_webhook_logs'), function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 100)->index();
            $table->string('status', 20)->index();
            $table->text('payload')->nullable();
            $table->text('message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('amember-sso.tables.webhook_logs', 'amember_webhook_logs'));
    }
};
