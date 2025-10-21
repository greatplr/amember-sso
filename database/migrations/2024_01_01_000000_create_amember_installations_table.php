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
        Schema::create('amember_installations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('api_url'); // Full API URL (e.g., https://example.com/api)
            $table->string('ip_address')->nullable(); // For webhook IP detection
            $table->string('login_url')->nullable(); // URL to redirect for SSO login
            $table->string('api_key');
            $table->string('webhook_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('slug');
            $table->index('ip_address');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amember_installations');
    }
};
