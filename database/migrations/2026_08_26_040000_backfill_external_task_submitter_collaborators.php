<?php

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tasks')
            ->where('submitter_type', ExternalUser::class)
            ->whereNotNull('submitter_id')
            ->orderBy('id')
            ->select(['id', 'submitter_id'])
            ->chunkById(500, function ($tasks) use ($now): void {
                DB::table('task_collaborators')->insertOrIgnore(
                    $tasks->map(fn (object $task) => [
                        'task_id' => $task->id,
                        'kind' => TaskCollaboratorKind::External->value,
                        'user_id' => null,
                        'external_user_id' => $task->submitter_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        // Backfilled rows are indistinguishable from collaborators explicitly retained by submitters.
    }
};
