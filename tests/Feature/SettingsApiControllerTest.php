<?php

use App\Models\Project;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('api settings lists mcp and shift sdk personal tokens separately', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'name' => 'Portal Integration',
    ]);

    $mcpPlainToken = $this->user->createToken('codex-mcp', ['mcp:use'])->plainTextToken;
    $sdkPlainToken = $this->user->createToken("shift-sdk-install:{$project->id}:20260603010101")->plainTextToken;
    $this->user->createToken('generic-api-token')->plainTextToken;

    $mcpToken = PersonalAccessToken::findToken($mcpPlainToken);
    $sdkToken = PersonalAccessToken::findToken($sdkPlainToken);

    $response = $this->actingAs($this->user)
        ->get(route('api.edit'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/Api')
        ->has('mcpTokens', 1)
        ->where('mcpTokens.0.id', $mcpToken->id)
        ->where('mcpTokens.0.name', 'codex-mcp')
        ->has('sdkTokens', 1)
        ->where('sdkTokens.0.id', $sdkToken->id)
        ->where('sdkTokens.0.project.id', $project->id)
        ->where('sdkTokens.0.project.name', 'Portal Integration')
    );
});

test('users can reset their mcp token', function () {
    $oldMcpPlainToken = $this->user->createToken('old-codex-mcp', ['mcp:use'])->plainTextToken;
    $secondMcpPlainToken = $this->user->createToken('second-codex-mcp', ['mcp:use'])->plainTextToken;
    $genericPlainToken = $this->user->createToken('generic-api-token')->plainTextToken;

    $oldMcpToken = PersonalAccessToken::findToken($oldMcpPlainToken);
    $secondMcpToken = PersonalAccessToken::findToken($secondMcpPlainToken);
    $genericToken = PersonalAccessToken::findToken($genericPlainToken);

    $response = $this->actingAs($this->user)
        ->postJson(route('api.tokens.mcp.reset'));

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'record' => ['id', 'name', 'created_at', 'last_used_at'],
        ]);

    expect(PersonalAccessToken::query()->whereKey($oldMcpToken->id)->exists())->toBeFalse();
    expect(PersonalAccessToken::query()->whereKey($secondMcpToken->id)->exists())->toBeFalse();
    expect(PersonalAccessToken::query()->whereKey($genericToken->id)->exists())->toBeTrue();

    $newToken = PersonalAccessToken::findToken($response->json('token'));

    expect($newToken)->not->toBeNull();
    expect($newToken->tokenable_id)->toBe($this->user->id);
    expect($newToken->abilities)->toContain('mcp:use');
});

test('users can reset one of their shift sdk tokens', function () {
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);
    $oldPlainToken = $this->user->createToken("shift-sdk-install:{$project->id}:20260603010101")->plainTextToken;
    $otherPlainToken = $this->user->createToken("shift-sdk-install:{$project->id}:20260603020202")->plainTextToken;

    $oldToken = PersonalAccessToken::findToken($oldPlainToken);
    $otherToken = PersonalAccessToken::findToken($otherPlainToken);

    $response = $this->actingAs($this->user)
        ->postJson(route('api.tokens.sdk.reset', $oldToken));

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'record' => ['id', 'name', 'project'],
        ])
        ->assertJsonPath('record.project.id', $project->id);

    expect(PersonalAccessToken::query()->whereKey($oldToken->id)->exists())->toBeFalse();
    expect(PersonalAccessToken::query()->whereKey($otherToken->id)->exists())->toBeTrue();

    $newToken = PersonalAccessToken::findToken($response->json('token'));

    expect($newToken)->not->toBeNull();
    expect($newToken->tokenable_id)->toBe($this->user->id);
    expect($newToken->name)->toStartWith("shift-sdk-install:{$project->id}:");
});

test('users cannot reset another users sdk token or a non sdk token', function () {
    $otherUser = User::factory()->create();
    $otherPlainToken = $otherUser->createToken('shift-sdk-install:1:20260603010101')->plainTextToken;
    $genericPlainToken = $this->user->createToken('generic-api-token')->plainTextToken;

    $otherToken = PersonalAccessToken::findToken($otherPlainToken);
    $genericToken = PersonalAccessToken::findToken($genericPlainToken);

    $this->actingAs($this->user)
        ->postJson(route('api.tokens.sdk.reset', $otherToken))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->postJson(route('api.tokens.sdk.reset', $genericToken))
        ->assertNotFound();
});
