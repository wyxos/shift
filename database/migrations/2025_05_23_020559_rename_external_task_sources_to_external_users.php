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
        Schema::rename('external_task_sources', 'external_users');

        Schema::table('external_users', function (Blueprint $table) {
            $table->renameColumn('submitter_name', 'name');
            $table->string('email')->nullable();
            $table->dropColumn(['source_url', 'environment']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('external_users', function (Blueprint $table) {
            $table->renameColumn('name', 'submitter_name');
            $table->dropColumn('email');
            $table->string('source_url');
            $table->string('environment')->default('production');
        });

        Schema::rename('external_users', 'external_task_sources');
    }
};
