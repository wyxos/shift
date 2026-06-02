<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('external_widget_enabled')->default(false)->after('token');
            $table->boolean('external_widget_guest_submissions_enabled')->default(false)->after('external_widget_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'external_widget_enabled',
                'external_widget_guest_submissions_enabled',
            ]);
        });
    }
};
