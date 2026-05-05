<?php

use App\Http\Middleware\EnsureShiftMcpTokenAbility;
use App\Mcp\Servers\ShiftServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

Mcp::local('shift', ShiftServer::class);

if (config('shift_mcp.web_enabled')) {
    Route::middleware([AddWwwAuthenticateHeader::class, 'auth:sanctum', EnsureShiftMcpTokenAbility::class])
        ->group(function (): void {
            Mcp::web('/mcp/shift', ShiftServer::class)
                ->middleware(['throttle:60,1']);
        });
}
