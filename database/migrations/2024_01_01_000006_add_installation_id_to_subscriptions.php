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
        $tableName = config('amember-sso.tables.subscriptions', 'amember_subscriptions');

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'installation_id')) {
                $table->foreignId('installation_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('amember_installations')
                    ->nullOnDelete();

                $table->index(['installation_id', 'user_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('amember-sso.tables.subscriptions', 'amember_subscriptions');

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'installation_id')) {
                $table->dropForeign(['installation_id']);
                $table->dropColumn('installation_id');
            }
        });
    }
};
