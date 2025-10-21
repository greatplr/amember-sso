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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'amember_installation_id')) {
                $table->foreignId('amember_installation_id')
                    ->nullable()
                    ->after('amember_user_id')
                    ->constrained('amember_installations')
                    ->nullOnDelete();

                $table->index(['amember_installation_id', 'amember_user_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'amember_installation_id')) {
                $table->dropForeign(['amember_installation_id']);
                $table->dropColumn('amember_installation_id');
            }
        });
    }
};
