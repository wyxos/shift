<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('tasks')
                ->whereNotNull('error_signature')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (object $task): string => $this->signature($task))
                ->each(function (Collection $tasks, string $signature): void {
                    $this->normalize($tasks, $signature);
                });
        });
    }

    public function down(): void
    {
        //
    }

    private function normalize(Collection $tasks, string $signature): void
    {
        $keeper = $this->keeper($tasks);
        $duplicates = $tasks->reject(fn (object $task): bool => $task->id === $keeper->id)->values();

        if ($duplicates->isNotEmpty()) {
            $this->merge($keeper, $duplicates);
        }

        $latest = $tasks
            ->sortByDesc(fn (object $task): string => (string) ($task->error_last_seen_at ?? $task->updated_at ?? $task->created_at ?? ''))
            ->first();

        DB::table('tasks')
            ->where('id', $keeper->id)
            ->update([
                'title' => $latest->title,
                'status' => $this->status($tasks),
                'error_signature' => $signature,
                'error_source' => $latest->error_source,
                'error_environment' => $latest->error_environment,
                'error_release' => $latest->error_release,
                'error_git_sha' => $latest->error_git_sha,
                'error_exception_class' => $latest->error_exception_class,
                'error_name' => $latest->error_name,
                'error_culprit_file' => $latest->error_culprit_file,
                'error_culprit_line' => $latest->error_culprit_line,
                'error_culprit_function' => $latest->error_culprit_function,
                'error_occurrences_count' => DB::table('task_error_occurrences')->where('task_id', $keeper->id)->count(),
                'error_first_seen_at' => $this->firstSeenAt($keeper->id, $tasks),
                'error_last_seen_at' => $this->lastSeenAt($keeper->id, $tasks),
                'updated_at' => now(),
            ]);
    }

    private function merge(object $keeper, Collection $duplicates): void
    {
        $duplicateIds = $duplicates->pluck('id')->all();

        DB::table('tasks')
            ->whereIn('id', $duplicateIds)
            ->update(['error_signature' => null]);

        $nextNumber = ((int) DB::table('task_error_occurrences')
            ->where('task_id', $keeper->id)
            ->max('number')) + 1;

        DB::table('task_error_occurrences')
            ->whereIn('task_id', $duplicateIds)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $occurrence) use ($keeper, &$nextNumber): void {
                DB::table('task_error_occurrences')
                    ->where('id', $occurrence->id)
                    ->update([
                        'task_id' => $keeper->id,
                        'number' => $nextNumber++,
                    ]);
            });

        DB::table('task_threads')
            ->whereIn('task_id', $duplicateIds)
            ->update(['task_id' => $keeper->id]);

        DB::table('attachments')
            ->where('attachable_type', 'App\\Models\\Task')
            ->whereIn('attachable_id', $duplicateIds)
            ->update(['attachable_id' => $keeper->id]);

        $this->mergeCollaborators($keeper->id, $duplicateIds);
        $this->mergeMetadata($keeper->id, $duplicateIds);

        DB::table('tasks')
            ->whereIn('id', $duplicateIds)
            ->delete();
    }

    private function mergeCollaborators(int $keeperId, array $duplicateIds): void
    {
        DB::table('task_collaborators')
            ->whereIn('task_id', $duplicateIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $collaborator) use ($keeperId): void {
                DB::table('task_collaborators')->updateOrInsert(
                    [
                        'task_id' => $keeperId,
                        'kind' => $collaborator->kind,
                        'user_id' => $collaborator->user_id,
                        'external_user_id' => $collaborator->external_user_id,
                    ],
                    [
                        'created_at' => $collaborator->created_at,
                        'updated_at' => $collaborator->updated_at,
                    ],
                );
            });

        DB::table('task_collaborators')
            ->whereIn('task_id', $duplicateIds)
            ->delete();
    }

    private function mergeMetadata(int $keeperId, array $duplicateIds): void
    {
        if (DB::table('task_metadata')->where('task_id', $keeperId)->exists()) {
            DB::table('task_metadata')
                ->whereIn('task_id', $duplicateIds)
                ->delete();

            return;
        }

        $metadata = DB::table('task_metadata')
            ->whereIn('task_id', $duplicateIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $metadata) {
            return;
        }

        DB::table('task_metadata')
            ->where('id', $metadata->id)
            ->update(['task_id' => $keeperId]);

        DB::table('task_metadata')
            ->whereIn('task_id', $duplicateIds)
            ->delete();
    }

    private function keeper(Collection $tasks): object
    {
        return $tasks
            ->sortBy(fn (object $task): string => sprintf(
                '%s:%010d',
                (string) ($task->error_first_seen_at ?? $task->created_at ?? ''),
                (int) $task->id,
            ))
            ->first();
    }

    private function status(Collection $tasks): string
    {
        $open = $tasks
            ->reject(fn (object $task): bool => $task->status === 'completed')
            ->sortByDesc(fn (object $task): string => (string) ($task->error_last_seen_at ?? $task->updated_at ?? $task->created_at ?? ''))
            ->first();

        return $open?->status ?? 'completed';
    }

    private function firstSeenAt(int $taskId, Collection $tasks): mixed
    {
        return DB::table('task_error_occurrences')->where('task_id', $taskId)->min('received_at')
            ?? $tasks->pluck('error_first_seen_at')->filter()->min()
            ?? $tasks->pluck('created_at')->filter()->min();
    }

    private function lastSeenAt(int $taskId, Collection $tasks): mixed
    {
        return DB::table('task_error_occurrences')->where('task_id', $taskId)->max('received_at')
            ?? $tasks->pluck('error_last_seen_at')->filter()->max()
            ?? $tasks->pluck('updated_at')->filter()->max();
    }

    private function signature(object $task): string
    {
        return hash('sha256', json_encode([
            'project_id' => $task->project_id,
            'environment' => $this->nullableString($task->error_environment),
            'source' => (string) $task->error_source,
            'name' => $this->nullableString($task->error_exception_class)
                ?? $this->nullableString($task->error_name)
                ?? 'Error',
            'file' => $this->nullableString($task->error_culprit_file),
            'line' => $this->nullableInt($task->error_culprit_line),
            'function' => $this->nullableString($task->error_culprit_function),
        ], JSON_THROW_ON_ERROR));
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
};
