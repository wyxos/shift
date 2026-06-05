<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::table('task_metadata', function (Blueprint $table) {
            $table->string('phase')->default('task')->after('intake_type');
            $table->foreignId('requirement_batch_id')->nullable()->after('phase')->constrained()->nullOnDelete();
            $table->string('submitted_title')->nullable()->after('requirement_batch_id');
            $table->text('submitted_description')->nullable()->after('submitted_title');
            $table->timestamp('finalized_at')->nullable()->after('submitted_description');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_metadata', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropConstrainedForeignId('requirement_batch_id');
            $table->dropColumn([
                'phase',
                'submitted_title',
                'submitted_description',
                'finalized_at',
            ]);
        });

        Schema::dropIfExists('requirement_batches');
    }
};
