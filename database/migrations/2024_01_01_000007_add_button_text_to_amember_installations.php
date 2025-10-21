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
        Schema::table('amember_installations', function (Blueprint $table) {
            $table->string('button_text', 100)->nullable()->after('login_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amember_installations', function (Blueprint $table) {
            $table->dropColumn('button_text');
        });
    }
};
