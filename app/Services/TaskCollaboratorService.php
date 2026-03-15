<?php

namespace App\Services;

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskCollaboratorService
{
    public function internalCandidates(Project $project, ?string $search = null): Collection
    {
        $projectUsers = $project->projectUser()
            ->whereNotNull('user_id')
            ->where('registration_status', 'registered')
            ->pluck('user_id');

        $userIds = $projectUsers
            ->when($project->author_id !== null, fn (Collection $ids) => $ids->push($project->author_id))
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->when(
                filled($search),
                function ($query) use ($search) {
                    $term = '%'.trim((string) $search).'%';

                    $query->where(function ($userQuery) use ($term) {
                        $userQuery
                            ->whereRaw('LOWER(name) LIKE LOWER(?)', [$term])
                            ->orWhereRaw('LOWER(email) LIKE LOWER(?)', [$term]);
                    });
                }
            )
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function validateInternalCollaboratorIds(Project $project, array $ids): array
    {
        $normalizedIds = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($normalizedIds->isEmpty()) {
            return [];
        }

        $validIds = $this->internalCandidates($project)->pluck('id');
        $invalidIds = $normalizedIds->diff($validIds);

        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'internal_collaborator_ids' => 'One or more internal collaborators are not registered on this project.',
            ]);
        }

        return $normalizedIds->all();
    }

    public function sync(Task $task, array $internalUserIds = [], iterable $externalUsers = []): void
    {
        $normalizedInternalIds = collect($internalUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $normalizedExternalIds = collect($externalUsers)
            ->map(function ($externalUser) {
                if ($externalUser instanceof ExternalUser) {
                    return $externalUser->id;
                }

                return is_array($externalUser) ? ($externalUser['id'] ?? null) : null;
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $now = now();

        DB::transaction(function () use ($task, $normalizedInternalIds, $normalizedExternalIds, $now) {
            $task->collaborators()->delete();

            $records = $normalizedInternalIds
                ->map(fn (int $userId) => [
                    'task_id' => $task->id,
                    'kind' => TaskCollaboratorKind::Internal->value,
                    'user_id' => $userId,
                    'external_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->concat(
                    $normalizedExternalIds->map(fn (int $externalUserId) => [
                        'task_id' => $task->id,
                        'kind' => TaskCollaboratorKind::External->value,
                        'user_id' => null,
                        'external_user_id' => $externalUserId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                )
                ->values()
                ->all();

            if ($records !== []) {
                TaskCollaborator::query()->insert($records);
            }
        });
    }

    public function internalAudience(Task $task, ?int $excludingUserId = null): Collection
    {
        $task->loadMissing(['project.author', 'project.projectUser.user', 'internalCollaborators']);

        $users = collect();

        if ($task->project?->author !== null && $task->project->author->id !== $excludingUserId) {
            $users->push($task->project->author);
        }

        foreach ($task->project?->projectUser ?? [] as $projectUser) {
            if ($projectUser->user === null || $projectUser->user->id === $excludingUserId) {
                continue;
            }

            if (! $users->contains('id', $projectUser->user->id)) {
                $users->push($projectUser->user);
            }
        }

        foreach ($task->internalCollaborators as $collaborator) {
            if ($collaborator->id === $excludingUserId) {
                continue;
            }

            if (! $users->contains('id', $collaborator->id)) {
                $users->push($collaborator);
            }
        }

        return $users->values();
    }

    public function externalAudience(Task $task, ?int $excludingExternalUserId = null): Collection
    {
        $task->loadMissing(['submitter', 'externalCollaborators']);

        $users = collect();

        if (
            $task->submitter instanceof ExternalUser &&
            $task->submitter->id !== $excludingExternalUserId
        ) {
            $users->push($task->submitter);
        }

        foreach ($task->externalCollaborators as $collaborator) {
            if ($collaborator->id === $excludingExternalUserId) {
                continue;
            }

            if (! $users->contains('id', $collaborator->id)) {
                $users->push($collaborator);
            }
        }

        return $users->values();
    }
}
