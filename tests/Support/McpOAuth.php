<?php

use App\Models\User;
use Laravel\Passport\ClientRepository;

function issueMcpRecoveryTokens(): array
{
    $user = User::factory()->create(['email' => '1@example.com']);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Refresh recovery test',
        redirectUris: ['http://127.0.0.1:43123/callback'],
        confidential: false,
    );
    $verifier = str_repeat('a', 64);
    test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => 'mcp:read mcp:write',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'resource' => config('shift_mcp.resource'),
    ]))->assertOk();
    $approval = test()->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
    parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $callback);
    $tokens = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'code' => $callback['code'],
        'code_verifier' => $verifier,
        'resource' => config('shift_mcp.resource'),
    ])->assertOk()->json();

    return [
        'grant_type' => 'refresh_token',
        'client_id' => $client->id,
        'refresh_token' => $tokens['refresh_token'],
        'resource' => config('shift_mcp.resource'),
    ];
}
