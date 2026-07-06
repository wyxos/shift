<?php

namespace App\Http\Middleware;

use App\Mcp\Support\ShiftMcpAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShiftMcpTokenAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $access = app(ShiftMcpAccess::class);

        if (! $token || ! $access->tokenHasExplicitAbility($token, ShiftMcpAccess::READ_ABILITY)) {
            abort(403);
        }

        return $next($request);
    }
}
