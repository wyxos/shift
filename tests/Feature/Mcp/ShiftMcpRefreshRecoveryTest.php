<?php

use Laravel\Passport\ClientRepository;

beforeEach(function (): void {
    config([
        'app.url' => 'https://shift.test',
        'shift_mcp.issuer' => 'https://shift.test',
        'shift_mcp.resource' => 'https://shift.test/mcp/shift',
    ]);
});

test('recovers a lost refresh response without issuing another token pair', function () {
    $request = issueMcpRecoveryTokens();
    $issued = $this->postJson('/oauth/token', $request)->assertOk()->json();

    // The client lost this response and retries with its original refresh token.
    $recovered = $this->postJson('/oauth/token', $request)->assertOk()->json();

    expect($recovered['access_token'])->toBe($issued['access_token'])
        ->and($recovered['refresh_token'])->toBe($issued['refresh_token']);
    $this->assertDatabaseCount('oauth_access_tokens', 2);
    $this->assertDatabaseCount('oauth_refresh_tokens', 2);

    $this->withToken($recovered['access_token'])->postJson('/mcp/shift', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [],
            'clientInfo' => ['name' => 'recovery-test', 'version' => '1'],
        ],
    ])->assertOk();
});

test('keeps a fixed recovery deadline and decreases the access token lifetime', function () {
    $request = issueMcpRecoveryTokens();
    $issued = $this->postJson('/oauth/token', $request)->assertOk()->json();
    $deadline = App\Models\McpRefreshRecovery::firstOrFail()->expires_at;

    $this->travel(30)->seconds();
    $recovered = $this->postJson('/oauth/token', $request)->assertOk()->json();
    expect($recovered['expires_in'])->toBeLessThanOrEqual($issued['expires_in'] - 30)
        ->and(App\Models\McpRefreshRecovery::firstOrFail()->expires_at->equalTo($deadline))->toBeTrue();

    $this->travelTo($deadline);
    $this->postJson('/oauth/token', $request)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    $this->assertDatabaseCount('oauth_access_tokens', 2);
});

test('does not recover a changed refresh request', function (string $change) {
    $request = issueMcpRecoveryTokens();
    $this->postJson('/oauth/token', $request)->assertOk();
    $changed = $request;
    if ($change === 'scope') {
        $changed['scope'] = 'mcp:read';
    } elseif ($change === 'client') {
        $changed['client_id'] = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'Another client', redirectUris: ['http://127.0.0.1:43124/callback'], confidential: false,
        )->id;
    } elseif ($change === 'resource') {
        $changed['resource'] = 'https://another.example/mcp';
    } else {
        $changed['refresh_token'] .= 'tampered';
    }

    $this->postJson('/oauth/token', $changed)->assertBadRequest();
    $this->postJson('/oauth/token', $request)->assertOk();
    $this->assertDatabaseCount('oauth_access_tokens', 2);
})->with(['scope', 'client', 'resource', 'token']);

test('does not replay credentials after revocation or expiration', function (string $change) {
    $request = issueMcpRecoveryTokens();
    $this->postJson('/oauth/token', $request)->assertOk();
    $recovery = App\Models\McpRefreshRecovery::firstOrFail();
    $client = Laravel\Passport\Passport::client()->findOrFail($request['client_id']);
    $access = Laravel\Passport\Passport::token()->findOrFail($recovery->access_token_id);
    $refresh = Laravel\Passport\Passport::refreshToken()->findOrFail($recovery->refresh_token_id);

    match ($change) {
        'client revoked' => $client->update(['revoked' => true]),
        'client confidential' => $client->update(['secret' => 'test-secret']),
        'grant removed' => $client->update(['grant_types' => ['authorization_code']]),
        'access revoked' => $access->revoke(),
        'refresh revoked' => $refresh->revoke(),
        'access expired' => $access->update(['expires_at' => now()->subSecond()]),
        'refresh expired' => $refresh->update(['expires_at' => now()->subSecond()]),
        'original expired' => Laravel\Passport\Passport::refreshToken()->where('id', '!=', $refresh->id)
            ->update(['expires_at' => now()->subSecond()]),
    };

    expect($this->postJson('/oauth/token', $request)->getStatusCode())->toBeIn([400, 401]);
    $this->assertDatabaseCount('oauth_access_tokens', 2);
})->with([
    'client revoked', 'client confidential', 'grant removed', 'access revoked',
    'refresh revoked', 'access expired', 'refresh expired', 'original expired',
]);

test('does not recover an older pair after the replacement refresh token is used', function () {
    $request = issueMcpRecoveryTokens();
    $first = $this->postJson('/oauth/token', $request)->assertOk()->json();
    $next = [...$request, 'refresh_token' => $first['refresh_token']];
    $second = $this->postJson('/oauth/token', $next)->assertOk()->json();

    $this->postJson('/oauth/token', $request)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    $this->postJson('/oauth/token', $next)->assertOk()->assertJsonPath('refresh_token', $second['refresh_token']);
    $this->assertDatabaseCount('oauth_access_tokens', 3);
});

test('stores recovery credentials encrypted and prunes expired receipts', function () {
    $request = issueMcpRecoveryTokens();
    $issued = $this->postJson('/oauth/token', $request)->assertOk()->json();
    $raw = Illuminate\Support\Facades\DB::table('mcp_refresh_recoveries')->first();
    expect($raw->payload)->not->toContain($issued['access_token'])
        ->not->toContain($issued['refresh_token'])
        ->and(json_encode($raw))->not->toContain($request['refresh_token']);

    $this->artisan('model:prune', ['--model' => [App\Models\McpRefreshRecovery::class]])->assertSuccessful();
    $this->assertDatabaseCount('mcp_refresh_recoveries', 1);
    $this->travel(61)->seconds();
    $this->artisan('model:prune', ['--model' => [App\Models\McpRefreshRecovery::class]])->assertSuccessful();
    $this->assertDatabaseCount('mcp_refresh_recoveries', 0);
    $this->postJson('/oauth/token', $request)->assertBadRequest();
});

test('rolls back rotation if the recovery receipt cannot be saved', function () {
    $request = issueMcpRecoveryTokens();
    $listener = 'eloquent.creating: '.App\Models\McpRefreshRecovery::class;
    Illuminate\Support\Facades\Event::listen($listener, function (): void {
        throw new RuntimeException('Simulated receipt storage failure');
    });
    $this->withoutExceptionHandling();
    try {
        $this->postJson('/oauth/token', $request);
        $this->fail('Expected receipt storage to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated receipt storage failure');
    } finally {
        Illuminate\Support\Facades\Event::forget($listener);
    }

    $this->assertDatabaseCount('oauth_access_tokens', 1);
    $this->assertDatabaseCount('oauth_refresh_tokens', 1);
    $this->assertDatabaseHas('oauth_refresh_tokens', ['revoked' => false]);
    $this->postJson('/oauth/token', $request)->assertOk();
});

test('does not retain recovery records for invalid refresh requests', function () {
    $request = issueMcpRecoveryTokens();
    $this->postJson('/oauth/token', [...$request, 'scope' => 'invalid-scope'])->assertBadRequest();
    $this->assertDatabaseCount('mcp_refresh_recoveries', 0);
    $this->assertDatabaseCount('oauth_access_tokens', 1);
    $this->postJson('/oauth/token', $request)->assertOk();
});

test('bounds delivery recovery by the original signed refresh expiry', function () {
    Laravel\Passport\Passport::refreshTokensExpireIn(new DateInterval('PT20S'));
    try {
        $request = issueMcpRecoveryTokens();
    } finally {
        Laravel\Passport\Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        app()->forgetInstance(League\OAuth2\Server\AuthorizationServer::class);
        app('router')->getRoutes()->getByName('passport.token')->flushController();
    }
    $this->postJson('/oauth/token', $request)->assertOk();
    $recovery = App\Models\McpRefreshRecovery::firstOrFail();
    expect($recovery->expires_at->getTimestamp())->toBeLessThanOrEqual(now()->addSeconds(20)->getTimestamp());
    $replacement = Laravel\Passport\Passport::refreshToken()->findOrFail($recovery->refresh_token_id);
    expect($replacement->expires_at->gt(now()->addDay()))->toBeTrue();

    $this->travelTo($recovery->expires_at);
    $this->postJson('/oauth/token', $request)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
});

test('does not replay credentials after supported scopes change', function () {
    $request = issueMcpRecoveryTokens();
    $this->postJson('/oauth/token', $request)->assertOk();
    Laravel\Passport\Passport::tokensCan(['mcp:read' => 'Read MCP data']);

    $this->postJson('/oauth/token', $request)->assertBadRequest();
});

test('does not bypass client authentication on recovery', function (bool $header) {
    $request = issueMcpRecoveryTokens();
    $this->postJson('/oauth/token', $request)->assertOk();
    if ($header) {
        $this->withHeader('Authorization', 'Basic '.base64_encode($request['client_id'].':incorrect'));
    } else {
        $request['client_secret'] = 'incorrect';
    }
    $this->postJson('/oauth/token', $request)->assertBadRequest();
    $this->assertDatabaseCount('oauth_access_tokens', 2);
})->with([true, false]);
