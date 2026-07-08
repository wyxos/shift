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
        Schema::create('task_collaborator_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('kind');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('external_user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'event', 'kind'], 'task_collab_notifications_task_event_kind_index');
            $table->index(['scheduled_at', 'sent_at', 'cancelled_at'], 'task_collab_notifications_pending_index');
            $table->index(['task_id', 'kind', 'user_id'], 'task_collab_notifications_internal_index');
            $table->index(['task_id', 'kind', 'external_user_id'], 'task_collab_notifications_external_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_collaborator_notifications');
    }
};
