<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('error_signature', 64)->nullable()->after('submitter_type');
            $table->string('error_source', 32)->nullable()->after('error_signature');
            $table->string('error_environment')->nullable()->after('error_source');
            $table->string('error_release')->nullable()->after('error_environment');
            $table->string('error_git_sha')->nullable()->after('error_release');
            $table->string('error_exception_class', 512)->nullable()->after('error_git_sha');
            $table->string('error_name', 512)->nullable()->after('error_exception_class');
            $table->string('error_culprit_file', 2048)->nullable()->after('error_name');
            $table->unsignedInteger('error_culprit_line')->nullable()->after('error_culprit_file');
            $table->string('error_culprit_function', 512)->nullable()->after('error_culprit_line');
            $table->unsignedInteger('error_occurrences_count')->default(0)->after('error_culprit_function');
            $table->timestamp('error_first_seen_at')->nullable()->after('error_occurrences_count');
            $table->timestamp('error_last_seen_at')->nullable()->after('error_first_seen_at');

            $table->unique(['project_id', 'error_signature'], 'tasks_project_error_signature_unique');
            $table->index(['project_id', 'error_source', 'error_last_seen_at'], 'tasks_project_error_last_seen_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_project_error_last_seen_index');
            $table->dropUnique('tasks_project_error_signature_unique');
            $table->dropColumn([
                'error_signature',
                'error_source',
                'error_environment',
                'error_release',
                'error_git_sha',
                'error_exception_class',
                'error_name',
                'error_culprit_file',
                'error_culprit_line',
                'error_culprit_function',
                'error_occurrences_count',
                'error_first_seen_at',
                'error_last_seen_at',
            ]);
        });
    }
};
