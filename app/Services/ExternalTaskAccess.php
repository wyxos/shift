<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

class ExternalTaskAccess
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_CLIENT_DEVELOPER = 'client_developer';

    public const ROLE_SHIFT_LEAD_DEVELOPER = 'shift_lead_developer';

    public const ROLE_SHIFT_DEVELOPER = 'shift_developer';

    public const ROLE_USER = 'user';

    public const ROLE_GUEST = 'guest';

    private const VIEW_ALL_PROJECT_ITEM_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_SHIFT_LEAD_DEVELOPER,
    ];

    private const VIEW_OWNER_AND_ASSIGNED_PROJECT_ITEM_ROLES = [
        self::ROLE_CLIENT_DEVELOPER,
        self::ROLE_SHIFT_DEVELOPER,
    ];

    private const REQUIREMENT_SUBMITTER_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_CLIENT_DEVELOPER,
        self::ROLE_SHIFT_LEAD_DEVELOPER,
        self::ROLE_SHIFT_DEVELOPER,
    ];

    public function role(ExternalIdentity $externalIdentity): string
    {
        return $externalIdentity->role?->value ?? self::ROLE_USER;
    }

    public function canViewAllProjectItems(ExternalIdentity $externalIdentity): bool
    {
        return in_array($this->role($externalIdentity), self::VIEW_ALL_PROJECT_ITEM_ROLES, true);
    }

    public function canViewOwnerAndAssignedProjectItems(ExternalIdentity $externalIdentity): bool
    {
        return in_array($this->role($externalIdentity), self::VIEW_OWNER_AND_ASSIGNED_PROJECT_ITEM_ROLES, true);
    }

    public function canSubmitRequirements(ExternalIdentity $externalIdentity): bool
    {
        return in_array($this->role($externalIdentity), self::REQUIREMENT_SUBMITTER_ROLES, true);
    }

    public function constrainVisibleProjectItems(Builder $query, ExternalIdentity $externalIdentity): Builder
    {
        if ($this->canViewAllProjectItems($externalIdentity)) {
            return $query;
        }

        return $query->where(function (Builder $visibilityQuery) use ($externalIdentity) {
            $linkedExternalIdentityIds = $this->linkedExternalIdentityIds($externalIdentity);

            $visibilityQuery->whereHasMorph('submitter', [ExternalIdentity::class], function (Builder $submitterQuery) use ($linkedExternalIdentityIds) {
                $submitterQuery->whereIn('external_identities.id', $linkedExternalIdentityIds);
            })->orWhereHas('externalCollaborators', function (Builder $collaboratorQuery) use ($linkedExternalIdentityIds) {
                $collaboratorQuery->whereIn('external_identities.id', $linkedExternalIdentityIds);
            });

            if (! $this->canViewOwnerAndAssignedProjectItems($externalIdentity)) {
                return;
            }

            $visibilityQuery->orWhereHasMorph('submitter', [ExternalIdentity::class], function (Builder $submitterQuery) {
                $submitterQuery->where('external_identities.role', self::ROLE_OWNER);
            });
        });
    }

    public function canViewProjectItem(Task $task, ExternalIdentity $externalIdentity): bool
    {
        if ($this->canViewAllProjectItems($externalIdentity)) {
            return true;
        }

        if ($this->isLinkedSubmitter($task, $externalIdentity)) {
            return true;
        }

        if ($task->externalCollaborators()
            ->whereIn('external_identities.id', $this->linkedExternalIdentityIds($externalIdentity))
            ->exists()) {
            return true;
        }

        if (! $this->canViewOwnerAndAssignedProjectItems($externalIdentity)) {
            return false;
        }

        $submitter = $task->submitter;

        return $submitter instanceof ExternalIdentity && $this->role($submitter) === self::ROLE_OWNER;
    }

    public function canMutateProjectItem(Task $task, ExternalIdentity $externalIdentity): bool
    {
        return $this->isSubmitter($task, $externalIdentity);
    }

    public function canCommentOnProjectItem(Task $task, ExternalIdentity $externalIdentity): bool
    {
        return $this->canViewProjectItem($task, $externalIdentity);
    }

    public function capabilityFlags(Task $task, ?ExternalIdentity $externalIdentity): array
    {
        $canMutate = $externalIdentity instanceof ExternalIdentity
            && $this->canMutateProjectItem($task, $externalIdentity);

        return [
            'can_edit' => $canMutate,
            'can_update_status' => $canMutate,
            'can_update_priority' => $canMutate,
            'can_delete' => $canMutate,
            'can_comment' => $externalIdentity instanceof ExternalIdentity
                && $this->canCommentOnProjectItem($task, $externalIdentity),
        ];
    }

    private function linkedExternalIdentityIds(ExternalIdentity $externalIdentity): array
    {
        $projectParticipantId = $externalIdentity->project_participant_id;

        if ($projectParticipantId === null) {
            return [(int) $externalIdentity->id];
        }

        return ExternalIdentity::query()
            ->where('project_id', $externalIdentity->project_id)
            ->where('project_participant_id', $projectParticipantId)
            ->pluck('id')
            ->push($externalIdentity->id)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function isLinkedSubmitter(Task $task, ExternalIdentity $externalIdentity): bool
    {
        return $task->submitter_type === ExternalIdentity::class
            && $task->submitter_id !== null
            && in_array((int) $task->submitter_id, $this->linkedExternalIdentityIds($externalIdentity), true);
    }

    private function isSubmitter(Task $task, ExternalIdentity $externalIdentity): bool
    {
        return $task->submitter_type === ExternalIdentity::class
            && (int) $task->submitter_id === (int) $externalIdentity->id;
    }
}
