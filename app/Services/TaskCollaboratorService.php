<?php

namespace App\Services;

use App\Enums\TaskCollaboratorKind;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        [$normalizedInternalIds, $normalizedExternalIds] = $this->normalizedSelection($task, $internalUserIds, $externalUsers);

        $this->persistSelection($task, $normalizedInternalIds, $normalizedExternalIds);
    }

    public function syncWithResult(Task $task, array $internalUserIds = [], iterable $externalUsers = []): array
    {
        $task->loadMissing(['submitter', 'internalCollaborators:id', 'externalCollaborators:id']);

        $beforeInternalIds = $task->internalCollaborators
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $beforeExternalIds = $task->externalCollaborators
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        [$afterInternalIds, $afterExternalIds] = $this->normalizedSelection($task, $internalUserIds, $externalUsers);

        $this->persistSelection($task, $afterInternalIds, $afterExternalIds);

        $addedInternalIds = $afterInternalIds->diff($beforeInternalIds)->values();
        $addedExternalIds = $afterExternalIds->diff($beforeExternalIds)->values();

        return [
            'internal_ids' => $afterInternalIds->all(),
            'external_ids' => $afterExternalIds->all(),
            'added_internal' => $addedInternalIds->isEmpty()
                ? new EloquentCollection()
                : User::query()->whereIn('id', $addedInternalIds)->get(),
            'added_external' => $addedExternalIds->isEmpty()
                ? new EloquentCollection()
                : ExternalUser::query()->whereIn('id', $addedExternalIds)->get(),
        ];
    }

    public function canManageForInternalUser(Task $task, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $task->loadMissing('internalCollaborators:id');

        if ($task->submitter_type === User::class && $task->submitter_id === $userId) {
            return true;
        }

        return $task->internalCollaborators->contains('id', $userId);
    }

    public function canManageForExternalUser(Task $task, ?ExternalUser $externalUser): bool
    {
        if ($externalUser === null) {
            return false;
        }

        return $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
    }

    public function internalTaskCreateAudience(Task $task): Collection
    {
        $task->loadMissing(['submitter', 'internalCollaborators']);

        $users = collect();

        if ($task->submitter instanceof User) {
            $users->push($task->submitter);
        }

        foreach ($task->internalCollaborators as $collaborator) {
            if (! $users->contains('id', $collaborator->id)) {
                $users->push($collaborator);
            }
        }

        return $users->values();
    }

    public function externalTaskCreateAudience(Task $task): Collection
    {
        $task->loadMissing(['submitter', 'externalCollaborators']);

        $users = collect();

        if ($task->submitter instanceof ExternalUser) {
            $users->push($task->submitter);
        }

        foreach ($task->externalCollaborators as $collaborator) {
            if (! $users->contains('id', $collaborator->id)) {
                $users->push($collaborator);
            }
        }

        return $users->values();
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

    private function persistSelection(Task $task, Collection $internalIds, Collection $externalIds): void
    {
        $now = now();

        DB::transaction(function () use ($task, $internalIds, $externalIds, $now) {
            $task->collaborators()->delete();

            $records = $internalIds
                ->map(fn (int $userId) => [
                    'task_id' => $task->id,
                    'kind' => TaskCollaboratorKind::Internal->value,
                    'user_id' => $userId,
                    'external_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->concat(
                    $externalIds->map(fn (int $externalUserId) => [
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

    private function normalizedSelection(Task $task, array $internalUserIds, iterable $externalUsers): array
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

                if (is_array($externalUser)) {
                    return $externalUser['id'] ?? null;
                }

                if (is_int($externalUser) || is_string($externalUser)) {
                    return $externalUser;
                }

                return null;
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($task->submitter_type === User::class && $task->submitter_id !== null) {
            $normalizedInternalIds = $normalizedInternalIds
                ->reject(fn (int $id) => $id === (int) $task->submitter_id)
                ->values();
        }

        if ($task->submitter_type === ExternalUser::class && $task->submitter_id !== null) {
            $normalizedExternalIds = $normalizedExternalIds
                ->reject(fn (int $id) => $id === (int) $task->submitter_id)
                ->values();
        }

        return [$normalizedInternalIds, $normalizedExternalIds];
    }
}