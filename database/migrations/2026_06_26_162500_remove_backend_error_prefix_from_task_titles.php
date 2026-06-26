<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('tasks')
                ->whereNotNull('error_signature')
                ->where('title', 'like', 'Backend error: %')
                ->orderBy('id')
                ->get(['id', 'title'])
                ->each(function (object $task): void {
                    DB::table('tasks')
                        ->where('id', $task->id)
                        ->update([
                            'title' => Str::after($task->title, 'Backend error: '),
                            'updated_at' => now(),
                        ]);
                });
        });
    }

    public function down(): void
    {
        //
    }
};
