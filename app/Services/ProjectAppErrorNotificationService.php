<?php

namespace App\Services;

use App\Enums\OrganisationRole;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ProjectAppErrorNotificationService
{
    /**
     * @return EloquentCollection<int, User>
     */
    public function eligibleUsers(Project $project): EloquentCollection
    {
        $userIds = $this->eligibleUserIds($project);

        if ($userIds->isEmpty()) {
            return new EloquentCollection;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email']);
    }

    /**
     * @return Collection<int, int>
     */
    public function eligibleUserIds(Project $project): Collection
    {
        $project->loadMissing([
            'client.organisation.organisationUsers',
            'organisation.organisationUsers',
        ]);

        $userIds = collect();

        if ($project->author_id !== null) {
            $userIds->push((int) $project->author_id);
        }

        $organisation = $project->accessOrganisation();

        if ($organisation?->author_id !== null) {
            $userIds->push((int) $organisation->author_id);
        }

        if ($organisation !== null) {
            $userIds = $userIds->merge(
                $organisation->organisationUsers
                    ->where('role', OrganisationRole::Administrator)
                    ->pluck('user_id')
            );
        }

        return $userIds
            ->merge($project->projectUser()->whereNotNull('user_id')->pluck('user_id'))
            ->map(fn ($userId) => (int) $userId)
            ->filter(fn (int $userId) => $userId > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, int>
     */
    public function sync(Project $project, array $userIds): Collection
    {
        $normalizedUserIds = collect($userIds)
            ->map(fn ($userId) => (int) $userId)
            ->filter(fn (int $userId) => $userId > 0)
            ->unique()
            ->values();

        $project->appErrorNotificationUsers()->sync($normalizedUserIds);

        return $this->selectedUserIds($project);
    }

    /**
     * @return Collection<int, int>
     */
    public function selectedUserIds(Project $project): Collection
    {
        $eligibleUserIds = $this->eligibleUserIds($project);

        if ($eligibleUserIds->isEmpty()) {
            return collect();
        }

        return $project->appErrorNotificationUsers()
            ->whereIn('users.id', $eligibleUserIds)
            ->pluck('users.id')
            ->map(fn ($userId) => (int) $userId)
            ->sort()
            ->values();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    public function recipients(Project $project): EloquentCollection
    {
        $eligibleUserIds = $this->eligibleUserIds($project);

        if ($eligibleUserIds->isEmpty()) {
            return new EloquentCollection;
        }

        return $project->appErrorNotificationUsers()
            ->whereIn('users.id', $eligibleUserIds)
            ->orderBy('users.name')
            ->orderBy('users.email')
            ->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * @param  iterable<int, ProjectUser>  $projectUsers
     */
    public function removeProjectUserRecipients(iterable $projectUsers): void
    {
        collect($projectUsers)
            ->filter(fn (ProjectUser $projectUser) => $projectUser->user_id !== null)
            ->groupBy('project_id')
            ->each(function (Collection $projectUsers, int $projectId): void {
                $project = Project::query()->find($projectId);

                if (! $project instanceof Project) {
                    return;
                }

                $project->appErrorNotificationUsers()->detach(
                    $projectUsers
                        ->pluck('user_id')
                        ->map(fn ($userId) => (int) $userId)
                        ->unique()
                        ->values()
                        ->all()
                );
            });
    }
}
