<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_threads', function (Blueprint $table) {
            $table->timestamp('emails_queued_at')->nullable();
        });
        Schema::create('task_thread_email_batches', function (Blueprint $table) {
            $table->id();
            $table->string('active_key', 64)->nullable()->unique();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('external_identity_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('recipient_snapshot')->nullable();
            $table->timestamp('scheduled_at');
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        Schema::create('task_thread_email_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('task_thread_email_batches')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('task_threads')->cascadeOnDelete();
            $table->string('recipient_key', 64);
            $table->unique(['thread_id', 'recipient_key']);
        });
        Schema::table('external_notification_deliveries', function (Blueprint $table) {
            $table->foreignId('task_thread_email_batch_id')->nullable()
                ->constrained('task_thread_email_batches', indexName: 'external_delivery_email_batch_fk')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_threads', function (Blueprint $table) {
            $table->dropColumn('emails_queued_at');
        });
        Schema::table('external_notification_deliveries', function (Blueprint $table) {
            $table->dropForeign('external_delivery_email_batch_fk');
            $table->dropColumn('task_thread_email_batch_id');
        });
        Schema::dropIfExists('task_thread_email_items');
        Schema::dropIfExists('task_thread_email_batches');
    }
};
