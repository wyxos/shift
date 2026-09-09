<?php

namespace App\Services;

use App\Enums\OrganisationRole;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class WidgetFeedbackAudience
{
    /** @return Collection<int, User> */
    public function recipients(Task $task): Collection
    {
        $project = $task->project;
        $organisation = $project?->accessOrganisation();

        if (! $organisation) {
            return collect();
        }

        $submitterEmail = $task->submitter instanceof ExternalUser ? $task->submitter->email : null;
        $users = User::query()->when(filled($submitterEmail), fn ($query) => $query
            ->whereRaw('LOWER(email) != ?', [mb_strtolower(trim($submitterEmail))]));

        $memberships = $organisation->organisationUsers()->get()->unique('user_id');
        $assignedIds = $project->projectUser()
            ->where('registration_status', 'registered')
            ->whereNotNull('user_id')
            ->pluck('user_id');
        $assignedRoleIds = $memberships->filter(fn ($membership): bool => in_array($membership->role, [
            OrganisationRole::LeadDeveloper,
            OrganisationRole::ClientProjectManager,
            OrganisationRole::Developer,
        ], true))->pluck('user_id')->reject(fn ($id): bool => $id === $organisation->author_id);

        $assigned = (clone $users)->whereIn('id', $assignedIds->intersect($assignedRoleIds))->get();

        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        $fallbackIds = $memberships->filter(fn ($membership): bool => in_array($membership->role, [
            OrganisationRole::Administrator,
            OrganisationRole::LeadDeveloper,
        ], true))->pluck('user_id')->push($organisation->author_id);

        return $users->whereIn('id', $fallbackIds)->get()
            ->filter(fn (User $user): bool => Task::query()->whereKey($task->id)->visibleTo($user->id)->exists())
            ->values();
    }
}
