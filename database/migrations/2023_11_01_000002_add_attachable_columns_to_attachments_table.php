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
        Schema::table('attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('attachments', 'attachable_id')) {
                $table->unsignedBigInteger('attachable_id')->nullable();
            }

            if (!Schema::hasColumn('attachments', 'attachable_type')) {
                $table->string('attachable_type')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['attachable_id', 'attachable_type']);
        });
    }
};
