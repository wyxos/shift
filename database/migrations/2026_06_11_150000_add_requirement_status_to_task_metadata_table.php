<?php

use App\Enums\RequirementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->string('requirement_status')
                ->default(RequirementStatus::Submitted->value)
                ->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->dropColumn('requirement_status');
        });
    }
};
