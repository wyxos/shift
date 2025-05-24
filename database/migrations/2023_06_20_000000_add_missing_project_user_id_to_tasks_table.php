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
        // Check if the column exists before adding it
        if (!Schema::hasColumn('tasks', 'project_user_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->foreignId('project_user_id')
                    ->nullable()
                    ->constrained()
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop the column if it exists
        if (Schema::hasColumn('tasks', 'project_user_id')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropForeign(['project_user_id']);
                $table->dropColumn('project_user_id');
            });
        }
    }
};
