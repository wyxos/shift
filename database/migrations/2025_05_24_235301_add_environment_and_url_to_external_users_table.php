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
        Schema::table('external_users', function (Blueprint $table) {
            $table->string('environment')->after('email');
            $table->string('url')->after('environment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_users', function (Blueprint $table) {
            $table->dropColumn(['environment', 'url']);
        });
    }
};
