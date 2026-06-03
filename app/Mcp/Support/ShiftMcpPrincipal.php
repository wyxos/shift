<?php

namespace App\Mcp\Support;

use App\Models\User;

class ShiftMcpPrincipal
{
    public function __construct(
        public readonly User $user,
    ) {}
}
