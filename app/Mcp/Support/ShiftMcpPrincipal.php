<?php

namespace App\Mcp\Support;

use App\Models\Project;
use App\Models\User;

class ShiftMcpPrincipal
{
    public function __construct(
        public readonly User $user,
        public readonly ?Project $project = null,
    ) {}

    public function hasProjectRestriction(): bool
    {
        return $this->project !== null;
    }
}
