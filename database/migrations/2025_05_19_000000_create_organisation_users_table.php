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
        if (!Schema::hasTable('organisation_users')) {
            Schema::create('organisation_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organisation_id')->constrained()->onDelete('cascade');
                $table->unsignedBigInteger('user_id');
                $table->string('user_email');
                $table->string('user_name');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organisation_users');
    }
};
