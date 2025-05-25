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
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->renameColumn('source_url', 'url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->renameColumn('url', 'source_url');
        });
    }
};
