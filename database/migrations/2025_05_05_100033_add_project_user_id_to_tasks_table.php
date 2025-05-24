<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration is no longer needed as the project_user_id column
        // has been added to the original tasks table migration
    }

    public function down(): void
    {
        // No action needed
    }
};
