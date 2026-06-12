<?php

namespace App\Services;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class ShiftPermissionService
{
    public function roleForOrganisation(Organisation $organisation, ?int $userId): ?OrganisationRole
    {
        if ($userId === null) {
            return null;
        }

        if ($organisation->author_id === $userId) {
            return OrganisationRole::Administrator;
        }

        $membership = $organisation->organisationUsers()
            ->where('user_id', $userId)
            ->first();

        return $membership?->role;
    }

    public function roleForProject(Project $project, ?int $userId): ?OrganisationRole
    {
        if ($userId === null) {
            return null;
        }

        if ($project->author_id === $userId || $project->isManagedByUser($userId)) {
            return OrganisationRole::Administrator;
        }

        $organisation = $project->accessOrganisation();
        if (! $organisation) {
            return null;
        }

        $role = $this->roleForOrganisation($organisation, $userId);

        if ($role === null) {
            return null;
        }

        if ($role === OrganisationRole::Administrator) {
            return $role;
        }

        return $this->hasProjectAccess($project, $userId) ? $role : null;
    }

    public function hasProjectAccess(Project $project, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return Project::query()
            ->whereKey($project->id)
            ->visibleTo($userId)
            ->exists();
    }

    public function canManageOrganisation(Organisation $organisation, ?int $userId): bool
    {
        return $this->roleForOrganisation($organisation, $userId)?->canManageOrganisation() === true;
    }

    public function canManageOrganisationAccess(Organisation $organisation, ?int $userId): bool
    {
        return $this->roleForOrganisation($organisation, $userId)?->canManageAccess() === true;
    }

    public function canAssignOrganisationRole(Organisation $organisation, ?int $userId, OrganisationRole $role): bool
    {
        return $this->roleForOrganisation($organisation, $userId)?->canAssignRole($role) === true;
    }

    public function canManageOrganisationUserRole(Organisation $organisation, OrganisationUser $organisationUser, ?int $userId): bool
    {
        if ($organisationUser->organisation_id !== $organisation->id || $this->isOrganisationOwnerUser($organisation, $organisationUser)) {
            return false;
        }

        $actingRole = $this->roleForOrganisation($organisation, $userId);
        $targetRole = $organisationUser->role ?? OrganisationRole::Developer;

        return $actingRole?->canManageRole($targetRole) === true;
    }

    public function canSetOrganisationUserRole(
        Organisation $organisation,
        OrganisationUser $organisationUser,
        ?int $userId,
        OrganisationRole $role,
    ): bool {
        if (! $this->canManageOrganisationUserRole($organisation, $organisationUser, $userId)) {
            return false;
        }

        return $this->roleForOrganisation($organisation, $userId)?->canAssignRole($role) === true;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function organisationRoleOptions(Organisation $organisation, ?int $userId, ?OrganisationUser $organisationUser = null): array
    {
        if ($organisationUser && ! $this->canManageOrganisationUserRole($organisation, $organisationUser, $userId)) {
            return [];
        }

        return array_map(
            static fn (OrganisationRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            OrganisationRole::assignableBy($this->roleForOrganisation($organisation, $userId)),
        );
    }

    public function canManageProjectAccess(Project $project, ?int $userId): bool
    {
        return $this->roleForProject($project, $userId)?->canManageAccess() === true;
    }

    public function canManageProject(Project $project, ?int $userId): bool
    {
        return $this->roleForProject($project, $userId)?->canManageOrganisation() === true;
    }

    public function canManageTechnicalSettings(Project $project, ?int $userId): bool
    {
        return $this->roleForProject($project, $userId)?->canManageTechnicalSettings() === true;
    }

    public function canManageExternalRoles(Project $project, ?int $userId): bool
    {
        return $this->roleForProject($project, $userId)?->canManageExternalRoles() === true;
    }

    public function canCreateTaskForProject(Project $project, ?int $userId): bool
    {
        return $this->roleForProject($project, $userId)?->canManageTaskScope() === true;
    }

    public function canEditTask(Task $task, ?int $userId): bool
    {
        $task->loadMissing('project');

        return $task->project instanceof Project
            && $this->roleForProject($task->project, $userId)?->canManageTaskScope() === true;
    }

    public function canFinalizeRequirement(Task $task, ?int $userId): bool
    {
        return $this->canEditTask($task, $userId);
    }

    public function canManageTaskCollaborators(Task $task, ?int $userId): bool
    {
        return $this->canEditTask($task, $userId);
    }

    public function canDeleteTask(Task $task, ?int $userId): bool
    {
        return $this->canEditTask($task, $userId);
    }

    public function canCommentOnTask(Task $task, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return Task::query()
            ->whereKey($task->id)
            ->visibleTo($userId)
            ->exists();
    }

    /**
     * @return array<string, bool>
     */
    public function taskCapabilities(Task $task, ?int $userId): array
    {
        $canEdit = $this->canEditTask($task, $userId);

        return [
            'can_comment' => $this->canCommentOnTask($task, $userId),
            'can_edit_task' => $canEdit && ! $task->isRequirementPhase(),
            'can_edit_requirement' => $canEdit && $task->isRequirementPhase(),
            'can_finalize_requirement' => $this->canFinalizeRequirement($task, $userId) && $task->isRequirementPhase(),
            'can_manage_collaborators' => $this->canManageTaskCollaborators($task, $userId),
            'can_delete' => $this->canDeleteTask($task, $userId),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function projectCapabilities(Project $project, ?int $userId): array
    {
        return [
            'can_manage_project_access' => $this->canManageProjectAccess($project, $userId),
            'can_edit_project' => $this->canManageProject($project, $userId),
            'can_delete_project' => $this->canManageProject($project, $userId),
            'can_manage_technical_settings' => $this->canManageTechnicalSettings($project, $userId),
            'can_create_task' => $this->canCreateTaskForProject($project, $userId),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function organisationCapabilities(Organisation $organisation, ?int $userId): array
    {
        return [
            'can_manage_organisation' => $this->canManageOrganisation($organisation, $userId),
            'can_manage_org_access' => $this->canManageOrganisationAccess($organisation, $userId),
        ];
    }

    public function restrictProjectsToManagedAccess(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $projectQuery) use ($userId) {
            $projectQuery
                ->where('author_id', $userId)
                ->orWhereHas('organisation', function (Builder $organisationQuery) use ($userId) {
                    $organisationQuery
                        ->where('author_id', $userId)
                        ->orWhereHas('organisationUsers', function (Builder $memberQuery) use ($userId) {
                            $memberQuery
                                ->where('user_id', $userId)
                                ->whereIn('role', [
                                    OrganisationRole::Administrator->value,
                                    OrganisationRole::LeadDeveloper->value,
                                    OrganisationRole::ClientProjectManager->value,
                                ]);
                        });
                })
                ->orWhereHas('client.organisation', function (Builder $organisationQuery) use ($userId) {
                    $organisationQuery
                        ->where('author_id', $userId)
                        ->orWhereHas('organisationUsers', function (Builder $memberQuery) use ($userId) {
                            $memberQuery
                                ->where('user_id', $userId)
                                ->whereIn('role', [
                                    OrganisationRole::Administrator->value,
                                    OrganisationRole::LeadDeveloper->value,
                                    OrganisationRole::ClientProjectManager->value,
                                ]);
                        });
                });
        });
    }

    private function isOrganisationOwnerUser(Organisation $organisation, OrganisationUser $organisationUser): bool
    {
        if ($organisation->author_id === null) {
            return false;
        }

        return $organisationUser->user_id === $organisation->author_id
            || strcasecmp((string) $organisationUser->user_email, (string) $organisation->author?->email) === 0;
    }
}
