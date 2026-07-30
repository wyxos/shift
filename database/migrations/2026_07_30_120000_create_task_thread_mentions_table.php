<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_thread_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_thread_id')->constrained('task_threads')->cascadeOnDelete();
            $table->string('kind');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('external_user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_thread_id', 'user_id'], 'thread_mentions_thread_user_unique');
            $table->unique(['task_thread_id', 'external_user_id'], 'thread_mentions_thread_external_unique');
            $table->index(['task_thread_id', 'kind'], 'thread_mentions_thread_kind_index');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE task_thread_mentions
                ADD CONSTRAINT task_thread_mentions_principal_check
                CHECK (
                    (kind = 'internal' AND user_id IS NOT NULL AND external_user_id IS NULL)
                    OR
                    (kind = 'external' AND user_id IS NULL AND external_user_id IS NOT NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_thread_mentions');
    }
};
