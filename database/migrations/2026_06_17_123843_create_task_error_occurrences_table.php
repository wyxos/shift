<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_error_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('source', 32);
            $table->string('environment')->nullable();
            $table->string('release')->nullable();
            $table->string('git_sha')->nullable();
            $table->string('exception_class', 512)->nullable();
            $table->string('error_name', 512)->nullable();
            $table->text('message')->nullable();
            $table->string('culprit_file', 2048)->nullable();
            $table->unsignedInteger('culprit_line')->nullable();
            $table->string('culprit_function', 512)->nullable();
            $table->string('request_method', 16)->nullable();
            $table->string('request_url', 2048)->nullable();
            $table->string('request_path', 2048)->nullable();
            $table->string('request_referrer', 2048)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->json('payload');
            $table->json('stacktrace')->nullable();
            $table->json('context')->nullable();
            $table->json('user')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'number']);
            $table->index(['task_id', 'received_at']);
            $table->index(['task_id', 'source', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_error_occurrences');
    }
};
