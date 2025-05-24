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
        // First, add the new polymorphic columns
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('submitter_id')->nullable();
            $table->string('submitter_type')->nullable();
        });

        // Migrate data from existing columns to the new polymorphic relationship
        $tasks = DB::table('tasks')->get();

        foreach ($tasks as $task) {
            $submitterId = null;
            $submitterType = null;

            // If task has an external user, use that as the submitter
            if (!is_null($task->external_user_id)) {
                $submitterId = $task->external_user_id;
                $submitterType = 'App\\Models\\ExternalUser';
            }
            // Otherwise, if task has a project user, use that as the submitter
            elseif (!is_null($task->project_user_id)) {
                $submitterId = $task->project_user_id;
                $submitterType = 'App\\Models\\ProjectUser';
            }

            // Update the task with the new polymorphic relationship
            if (!is_null($submitterId) && !is_null($submitterType)) {
                DB::table('tasks')
                    ->where('id', $task->id)
                    ->update([
                        'submitter_id' => $submitterId,
                        'submitter_type' => $submitterType,
                    ]);
            }
        }

        // Remove the old columns except author_id
        Schema::table('tasks', function (Blueprint $table) {
            // Keep author_id column for backward compatibility
            // $table->dropForeign(['author_id']);
            // $table->dropColumn('author_id');

            $table->dropForeign(['external_user_id']);
            $table->dropColumn('external_user_id');

            $table->dropForeign(['project_user_id']);
            $table->dropColumn('project_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, add back the old columns
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('external_user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('project_user_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Migrate data from the polymorphic relationship back to the old columns
        $tasks = DB::table('tasks')->get();

        foreach ($tasks as $task) {
            $externalUserId = null;
            $projectUserId = null;

            if ($task->submitter_type === 'App\\Models\\ExternalUser') {
                $externalUserId = $task->submitter_id;
            } elseif ($task->submitter_type === 'App\\Models\\ProjectUser') {
                $projectUserId = $task->submitter_id;
            }

            // Update the task with the old relationships
            DB::table('tasks')
                ->where('id', $task->id)
                ->update([
                    'external_user_id' => $externalUserId,
                    'project_user_id' => $projectUserId,
                ]);
        }

        // Remove the polymorphic columns
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['submitter_id', 'submitter_type']);
        });
    }
};
