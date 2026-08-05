<?php

use App\Http\Controllers\Mcp\OAuthAuthorizationServerController;
use App\Http\Controllers\Mcp\OAuthProtectedResourceController;
use App\Http\Controllers\Mcp\RegisterOAuthClientController;
use App\Http\Middleware\AddMcpOAuthChallenge;
use App\Http\Middleware\EnsureMcpOAuthAudience;
use App\Http\Middleware\EnsureMcpOAuthResource;
use App\Mcp\Servers\ShiftServer;
use App\Mcp\Support\ShiftMcpAccess;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Middleware\CheckToken;

if (config('shift_mcp.http_enabled')) {
    Route::get('/.well-known/oauth-protected-resource', OAuthProtectedResourceController::class)
        ->name('mcp.oauth.protected-resource');
    Route::get('/mcp/shift/.well-known/oauth-protected-resource', OAuthProtectedResourceController::class);
    Route::get('/.well-known/oauth-authorization-server', OAuthAuthorizationServerController::class)
        ->name('mcp.oauth.authorization-server');

    Route::prefix('oauth')->name('passport.')->group(function (): void {
        Route::post('/register', RegisterOAuthClientController::class)
            ->middleware('throttle:oauth-client-registration')
            ->name('clients.register');

        Route::post('/token', [AccessTokenController::class, 'issueToken'])
            ->middleware(['throttle:60,1', EnsureMcpOAuthResource::class])
            ->name('token');

        Route::get('/authorize', [AuthorizationController::class, 'authorize'])
            ->middleware(['web', 'auth', 'verified', EnsureMcpOAuthResource::class])
            ->name('authorizations.authorize');

        Route::post('/authorize', [ApproveAuthorizationController::class, 'approve'])
            ->middleware(['web', 'auth', 'verified'])
            ->name('authorizations.approve');

        Route::delete('/authorize', [DenyAuthorizationController::class, 'deny'])
            ->middleware(['web', 'auth', 'verified'])
            ->name('authorizations.deny');
    });

    Route::middleware([
        AddMcpOAuthChallenge::class,
        'auth:mcp',
        EnsureMcpOAuthAudience::class,
        CheckToken::using(ShiftMcpAccess::READ_SCOPE),
    ])
        ->group(function (): void {
            Mcp::web('/mcp/shift', ShiftServer::class)
                ->middleware(['throttle:60,1']);
        });
}
