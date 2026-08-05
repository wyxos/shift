<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('OAuth cutover revokes only legacy MCP personal access tokens', function () {
    $user = User::factory()->create();
    $readToken = PersonalAccessToken::findToken(
        $user->createToken('legacy-mcp-read', ['mcp:use'])->plainTextToken,
    );
    $writeToken = PersonalAccessToken::findToken(
        $user->createToken('legacy-mcp-write', ['mcp:use', 'mcp:write'])->plainTextToken,
    );
    $sdkToken = PersonalAccessToken::findToken(
        $user->createToken('shift-sdk-install:1:20260805010101')->plainTextToken,
    );
    $genericToken = PersonalAccessToken::findToken(
        $user->createToken('generic-api-token')->plainTextToken,
    );

    $migration = require database_path('migrations/2026_08_05_114817_revoke_legacy_mcp_personal_access_tokens.php');
    $migration->up();

    expect(PersonalAccessToken::query()->whereKey($readToken->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($writeToken->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($sdkToken->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($genericToken->id)->exists())->toBeTrue();
});
