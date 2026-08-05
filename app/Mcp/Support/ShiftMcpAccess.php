<?php

namespace App\Mcp\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;

class ShiftMcpAccess
{
    public const READ_SCOPE = 'mcp:read';

    public const WRITE_SCOPE = 'mcp:write';

    public function principal(Request $request): ?ShiftMcpPrincipal
    {
        $user = $this->requestUser($request);

        if (! $user) {
            return null;
        }

        return new ShiftMcpPrincipal($user);
    }

    public function canWrite(ShiftMcpPrincipal $principal): bool
    {
        return $this->tokenHasExplicitScope(
            $principal->user->currentAccessToken(),
            self::WRITE_SCOPE,
        );
    }

    public function tokenHasExplicitScope(mixed $token, string $scope): bool
    {
        return is_object($token)
            && method_exists($token, 'can')
            && $token->can($scope) === true;
    }

    public function projectsFor(ShiftMcpPrincipal $principal): Builder
    {
        return $this->projects($principal->user)
            ->where('mcp_enabled', true);
    }

    public function tasksFor(ShiftMcpPrincipal $principal): Builder
    {
        return Task::query()
            ->visibleTo($principal->user->id)
            ->whereHas('project', fn (Builder $query) => $query->where('mcp_enabled', true));
    }

    protected function projects(User $user): Builder
    {
        return Project::query()->visibleTo($user->id);
    }

    protected function requestUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
