<?php

namespace App\Mcp\Support;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;

class ShiftMcpAccess
{
    public function principal(Request $request): ?ShiftMcpPrincipal
    {
        $user = $this->requestUser($request);

        if (! $user) {
            return null;
        }

        return new ShiftMcpPrincipal($user);
    }

    public function projectsFor(ShiftMcpPrincipal $principal): Builder
    {
        return $this->projects($principal->user);
    }

    public function tasksFor(ShiftMcpPrincipal $principal): Builder
    {
        return Task::query()->visibleTo($principal->user->id);
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
