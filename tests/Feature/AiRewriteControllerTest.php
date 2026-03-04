<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;
    $this->project = Project::factory()->create([
        'token' => 'project-token',
        'author_id' => $this->user->id,
    ]);

    config()->set('shift_ai.provider', 'ollama');
    config()->set('shift_ai.model', 'llama3.1');
    config()->set('shift_ai.ollama.base_url', 'http://127.0.0.1:11434');
    config()->set('shift_ai.timeout', 30);
});

test('web ai improve endpoint returns improved html', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'message' => [
                'content' => '<p>Improved note [[SHIFT_KEEP_1]]</p>',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>original [[SHIFT_KEEP_1]]</p>',
        'protected_tokens' => ['[[SHIFT_KEEP_1]]'],
        'context' => '1. Alice: prior comment context',
    ]);

    $response->assertOk();
    $response->assertJsonPath('improved_html', '<p>Improved note [[SHIFT_KEEP_1]]</p>');

    Http::assertSent(function ($request) {
        $userPrompt = (string) data_get($request->data(), 'messages.1.content', '');

        return str_contains($userPrompt, 'Thread context (for reference, use when rewriting):')
            && str_contains($userPrompt, '1. Alice: prior comment context');
    });
});

test('external api ai improve endpoint returns improved html', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'message' => [
                'content' => '<p>Improved via api</p>',
            ],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/ai/improve', [
        'project' => $this->project->token,
        'html' => '<p>Original</p>',
        'context' => '1. Bob: external context',
    ]);

    $response->assertOk();
    $response->assertJsonPath('improved_html', '<p>Improved via api</p>');
});

test('ai improve endpoint rejects model output that loses protected tokens', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'message' => [
                'content' => '<p>Token disappeared</p>',
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>Original [[SHIFT_KEEP_1]]</p>',
        'protected_tokens' => ['[[SHIFT_KEEP_1]]'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error', 'AI response did not preserve protected placeholders.');
});
