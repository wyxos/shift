<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_thread_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_thread_id')->constrained()->cascadeOnDelete();
            $table->morphs('actor');
            $table->string('emoji_key', 16);
            $table->unique(['task_thread_id', 'actor_type', 'actor_id', 'emoji_key'], 'task_thread_reactions_actor_emoji_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_thread_reactions');
    }
};
