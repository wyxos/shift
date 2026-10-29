<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_error_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scheduled_for');
            $table->string('summary_time', 5);
            $table->string('summary_timezone', 100);
            $table->timestamp('dispatch_requested_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivery_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_error_summaries');
    }
};
