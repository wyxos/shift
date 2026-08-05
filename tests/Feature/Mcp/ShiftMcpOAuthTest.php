<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config([
        'app.url' => 'https://shift.test',
        'shift_mcp.issuer' => 'https://shift.test',
        'shift_mcp.resource' => 'https://shift.test/mcp/shift',
    ]);
});

function registerMcpOAuthClient(): string
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Codex test client',
        'redirect_uris' => ['http://127.0.0.1:43123/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp:read mcp:write',
    ])
        ->assertCreated()
        ->assertJsonMissing(['client_secret'])
        ->json('client_id');
}

test('publishes OAuth authorization server and protected resource metadata', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJson([
            'resource' => 'https://shift.test/mcp/shift',
            'authorization_servers' => ['https://shift.test'],
            'scopes_supported' => ['mcp:read', 'mcp:write'],
            'bearer_methods_supported' => ['header'],
        ]);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJson([
            'issuer' => 'https://shift.test',
            'authorization_endpoint' => 'https://shift.test/oauth/authorize',
            'token_endpoint' => 'https://shift.test/oauth/token',
            'registration_endpoint' => 'https://shift.test/oauth/register',
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
});

test('registers only public OAuth clients with safe redirect URIs', function () {
    $clientId = registerMcpOAuthClient();

    expect($clientId)->toBeString()->not->toBeEmpty();

    $this->postJson('/oauth/register', [
        'client_name' => 'Unsafe client',
        'redirect_uris' => ['http://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['redirect_uris']);

    $this->postJson('/oauth/register', [
        'client_name' => 'Secret client',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token_endpoint_auth_method']);
});

test('completes a PKCE OAuth flow and uses the resulting token for MCP', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create([
        'mcp_enabled' => true,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'OAuth-visible MCP task',
    ]);

    $clientId = registerMcpOAuthClient();
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $authorizationQuery = [
        'client_id' => $clientId,
        'redirect_uri' => 'http://127.0.0.1:43123/callback',
        'response_type' => 'code',
        'scope' => 'mcp:read mcp:write',
        'state' => 'oauth-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'resource' => 'https://shift.test/mcp/shift',
    ];

    $authorizationResponse = $this->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query($authorizationQuery));

    $authorizationResponse
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/OAuth/Authorize')
            ->where('client.name', 'Codex test client')
            ->has('scopes', 2)
            ->where('scopes.0.id', 'mcp:read')
            ->where('scopes.1.id', 'mcp:write')
        );

    $authToken = $this->app['session']->get('authToken');

    $approvalResponse = $this->actingAs($user)
        ->post('/oauth/authorize', ['auth_token' => $authToken])
        ->assertRedirect();
    parse_str((string) parse_url($approvalResponse->headers->get('Location'), PHP_URL_QUERY), $callbackQuery);

    expect($callbackQuery)
        ->toHaveKey('code')
        ->toHaveKey('state', 'oauth-state');

    $tokenResponse = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://127.0.0.1:43123/callback',
        'code' => $callbackQuery['code'],
        'code_verifier' => $verifier,
        'resource' => 'https://shift.test/mcp/shift',
    ])
        ->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token']);

    $accessToken = $tokenResponse->json('access_token');
    $claims = json_decode(base64_decode(strtr(explode('.', $accessToken)[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);

    expect($claims['iss'])->toBe('https://shift.test')
        ->and($claims['aud'])->toContain($clientId, 'https://shift.test/mcp/shift')
        ->and($claims['scopes'])->toBe(['mcp:read', 'mcp:write']);

    $this->withHeader('Authorization', 'Bearer '.$accessToken)
        ->postJson('/mcp/shift', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'pest',
                    'version' => '0.0.1',
                ],
            ],
        ])
        ->assertOk()
        ->assertHeader('MCP-Session-Id');

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokenResponse->json('refresh_token'),
        'resource' => 'https://shift.test/mcp/shift',
    ])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'refresh_token']);
});

test('rejects authorization and token requests for another resource', function () {
    $user = User::factory()->create();
    $clientId = registerMcpOAuthClient();

    $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'http://127.0.0.1:43123/callback',
        'response_type' => 'code',
        'scope' => 'mcp:read',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'resource' => 'https://another.example/mcp',
    ]))
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_target');

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => 'not-a-token',
    ])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_target');
});
