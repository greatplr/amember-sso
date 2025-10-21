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
        $tableName = config('amember-sso.tables.products', 'amember_products');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')
                ->constrained(config('amember-sso.tables.installations', 'amember_installations'))
                ->cascadeOnDelete();

            // aMember product details
            $table->string('product_id'); // aMember's product ID (string to handle any format)
            $table->string('title')->nullable(); // Synced from aMember
            $table->text('description')->nullable(); // Synced from aMember

            // Local application mapping
            $table->string('tier')->nullable(); // e.g., 'basic', 'premium', 'enterprise'
            $table->string('display_name')->nullable(); // e.g., 'Premium Membership'
            $table->string('slug')->nullable(); // URL-friendly identifier

            // Polymorphic mapping (optional - for mapping to specific models)
            $table->string('mappable_type')->nullable(); // e.g., 'App\Models\Course'
            $table->unsignedBigInteger('mappable_id')->nullable(); // ID of the mapped model

            // Feature flags (JSON for flexibility)
            $table->json('features')->nullable(); // e.g., {"can_use_api": true, "max_users": 10}

            // Pricing info (optional, for display purposes)
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('billing_period')->nullable(); // 'monthly', 'yearly', 'lifetime'

            // Sorting and display
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);

            // Additional metadata
            $table->json('metadata')->nullable(); // Any other custom data

            $table->timestamps();

            // Composite unique constraint - one tier per product per installation
            $table->unique(['installation_id', 'product_id'], 'unique_installation_product');

            // Index for common queries
            $table->index(['installation_id', 'tier']);
            $table->index(['installation_id', 'is_active']);
            $table->index(['mappable_type', 'mappable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('amember-sso.tables.products', 'amember_products'));
    }
};
