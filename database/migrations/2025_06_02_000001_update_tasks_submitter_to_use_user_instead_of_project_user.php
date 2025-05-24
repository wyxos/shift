<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all tasks with ProjectUser as submitter
        $tasks = DB::table('tasks')
            ->where('submitter_type', 'App\\Models\\ProjectUser')
            ->get();

        // For each task, update the submitter to point to the User instead of ProjectUser
        foreach ($tasks as $task) {
            // Get the project_user record to find the associated user_id
            $projectUser = DB::table('project_users')
                ->where('id', $task->submitter_id)
                ->first();

            if ($projectUser) {
                // Update the task to point to the User instead of ProjectUser
                DB::table('tasks')
                    ->where('id', $task->id)
                    ->update([
                        'submitter_id' => $projectUser->user_id,
                        'submitter_type' => 'App\\Models\\User',
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration cannot be reversed automatically because we would need to know
        // which ProjectUser record to associate with each User.
        // If needed, a more complex down migration could be implemented.
    }
};
