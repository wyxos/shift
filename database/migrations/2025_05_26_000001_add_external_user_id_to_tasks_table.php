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
        // Add external_user_id to tasks table
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('external_user_id')->nullable()->constrained()->onDelete('set null');
        });

        // Migrate data from pivot table to tasks table
        if (Schema::hasTable('task_external_user')) {
            $pivotRecords = DB::table('task_external_user')->get();

            foreach ($pivotRecords as $record) {
                // For each task, set the external_user_id
                // If a task has multiple external users, only the first one will be kept
                DB::table('tasks')
                    ->where('id', $record->task_id)
                    ->update(['external_user_id' => $record->external_user_id]);
            }
        }

        // Drop the pivot table
        Schema::dropIfExists('task_external_user');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the pivot table
        Schema::create('task_external_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('external_user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Add a unique constraint to prevent duplicates
            $table->unique(['task_id', 'external_user_id']);
        });

        // Migrate data from tasks table to pivot table
        $tasksWithExternalUser = DB::table('tasks')
            ->whereNotNull('external_user_id')
            ->get(['id', 'external_user_id']);

        foreach ($tasksWithExternalUser as $task) {
            DB::table('task_external_user')->insert([
                'task_id' => $task->id,
                'external_user_id' => $task->external_user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Remove external_user_id from tasks table
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['external_user_id']);
            $table->dropColumn('external_user_id');
        });
    }
};
