<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('app_error_recurrence_enabled')->default(true);
            $table->unsignedSmallInteger('app_error_quiet_hours')->default(1);
            $table->boolean('app_error_daily_summary_enabled')->default(true);
            $table->string('app_error_daily_summary_time', 5)->default('09:00');
            $table->string('app_error_daily_summary_timezone')->default('UTC');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->timestamp('error_last_alert_at')->nullable();
        });
        Schema::table('task_error_occurrences', function (Blueprint $table): void {
            $table->string('notification_reason', 20)->nullable();
            $table->string('notification_status', 24)->nullable();
            $table->timestamp('notification_queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_error_occurrences', function (Blueprint $table): void {
            $table->dropColumn(['notification_reason', 'notification_status', 'notification_queued_at']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('error_last_alert_at');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['app_error_recurrence_enabled', 'app_error_quiet_hours', 'app_error_daily_summary_enabled', 'app_error_daily_summary_time', 'app_error_daily_summary_timezone']);
        });
    }
};
