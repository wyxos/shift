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
        // Make organisation_id nullable in clients table
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->change();
        });

        // Make client_id nullable in projects table
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
        });

        // Add organisation_id to projects table
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->after('client_id')->constrained()->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove organisation_id from projects table
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['organisation_id']);
            $table->dropColumn('organisation_id');
        });

        // Make client_id required again in projects table
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
        });

        // Make organisation_id required again in clients table
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable(false)->change();
        });
    }
};
