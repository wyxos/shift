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
        // Add the project_user_id column if it doesn't exist
        if (Schema::hasTable('tasks') && !Schema::hasColumn('tasks', 'project_user_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('project_user_id')
                    ->nullable()
                    ->constrained()
                    ->onDelete('cascade');
            });
        }

        // Rename the name column to title if it exists and title doesn't exist
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'name') && !Schema::hasColumn('tasks', 'title')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->renameColumn('name', 'title');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No action needed for down migration
    }
};
