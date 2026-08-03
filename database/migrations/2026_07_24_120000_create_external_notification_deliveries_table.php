<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('delivery_id')->unique();
            $table->string('source_type');
            $table->string('source_key', 64);
            $table->string('handler');
            $table->foreignId('task_collaborator_notification_id')
                ->nullable()
                ->constrained(indexName: 'external_deliveries_notification_fk')
                ->nullOnDelete();
            $table->foreignId('task_thread_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->string('last_failure_type')->nullable();
            $table->boolean('production')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('callback_delivered_at')->nullable();
            $table->timestamp('fallback_dispatched_at')->nullable();
            $table->timestamp('fallback_attempted_at')->nullable();
            $table->timestamp('fallback_sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_key']);
            $table->index(['completed_at', 'failed_at', 'cancelled_at'], 'external_deliveries_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_notification_deliveries');
    }
};
