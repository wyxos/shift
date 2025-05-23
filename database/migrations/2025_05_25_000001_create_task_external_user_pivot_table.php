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
        // Create the pivot table
        Schema::create('task_external_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('external_user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Add a unique constraint to prevent duplicates
            $table->unique(['task_id', 'external_user_id']);
        });

        // Remove task_id from external_users table
        Schema::table('external_users', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropColumn('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add task_id back to external_users table
        Schema::table('external_users', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Drop the pivot table
        Schema::dropIfExists('task_external_user');
    }
};
