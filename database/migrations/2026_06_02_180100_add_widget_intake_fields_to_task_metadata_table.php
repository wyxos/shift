<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->string('source')->nullable()->after('url');
            $table->string('intake_type')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->dropColumn(['source', 'intake_type']);
        });
    }
};
