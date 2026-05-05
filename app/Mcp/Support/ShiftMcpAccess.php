<?php

namespace App\Mcp\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ShiftMcpAccess
{
    public function principal(Request $request): ?ShiftMcpPrincipal
    {
        $user = $this->requestUser($request)
            ?? $this->userFromConfiguredToken()
            ?? $this->userFromConfiguredEmail();

        if (! $user) {
            return null;
        }

        $projectToken = $this->configuredProjectToken();
        $project = $projectToken ? $this->configuredProject($projectToken) : null;

        if ($projectToken && ! $project) {
            return null;
        }

        if ($project && ! $this->projects($user)->whereKey($project->id)->exists()) {
            return null;
        }

        return new ShiftMcpPrincipal($user, $project);
    }

    public function projectsFor(ShiftMcpPrincipal $principal): Builder
    {
        $query = $this->projects($principal->user);

        if ($principal->project) {
            $query->whereKey($principal->project->id);
        }

        return $query;
    }

    public function tasksFor(ShiftMcpPrincipal $principal): Builder
    {
        $query = Task::query()->visibleTo($principal->user->id);

        if ($principal->project) {
            $query->where('project_id', $principal->project->id);
        }

        return $query;
    }

    protected function projects(User $user): Builder
    {
        return Project::query()
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->where('author_id', $user->id)
                    ->orWhereHas('projectUser', fn (Builder $projectUserQuery) => $projectUserQuery->where('user_id', $user->id))
                    ->orWhereHas('organisation', fn (Builder $organisationQuery) => $organisationQuery->where('author_id', $user->id))
                    ->orWhereHas('client.organisation', fn (Builder $organisationQuery) => $organisationQuery->where('author_id', $user->id))
                    ->orWhereHas('tasks', fn (Builder $taskQuery) => $taskQuery->visibleTo($user->id));
            });
    }

    protected function requestUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    protected function userFromConfiguredToken(): ?User
    {
        $token = config('shift_mcp.auth_token');

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! in_array('mcp:use', $accessToken->abilities ?? [], true)) {
            return null;
        }

        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    protected function userFromConfiguredEmail(): ?User
    {
        $email = config('shift_mcp.user_email');

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return User::query()
            ->where('email', $email)
            ->first();
    }

    protected function configuredProjectToken(): ?string
    {
        $token = config('shift_mcp.project_token');

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        return trim($token);
    }

    protected function configuredProject(string $token): ?Project
    {
        return Project::query()
            ->where('token', $token)
            ->first();
    }
}
