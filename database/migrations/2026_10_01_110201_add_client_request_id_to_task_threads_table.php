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
        Schema::table('task_threads', function (Blueprint $table) {
            $table->uuid('client_request_id')->nullable();
            $table->unique(['task_id', 'sender_type', 'sender_id', 'client_request_id'], 'task_threads_client_request_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_threads', function (Blueprint $table) {
            $table->dropUnique('task_threads_client_request_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
