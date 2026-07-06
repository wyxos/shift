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
    public const READ_ABILITY = 'mcp:use';

    public const WRITE_ABILITY = 'mcp:write';

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
        return $this->tokenHasExplicitAbility(
            $principal->user->currentAccessToken(),
            self::WRITE_ABILITY,
        );
    }

    public function tokenHasExplicitAbility(mixed $token, string $ability): bool
    {
        if ($token instanceof PersonalAccessToken) {
            return in_array($ability, $token->abilities ?? [], true);
        }

        return is_object($token)
            && method_exists($token, 'can')
            && $token->can($ability) === true;
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
