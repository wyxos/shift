<?php

use App\Ai\Agents\ContentRewriteAgent;
use App\Models\Project;
use App\Models\User;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;
    $this->project = Project::factory()->create([
        'token' => 'project-token',
        'author_id' => $this->user->id,
    ]);

    config()->set('ai_features.rewrite.enabled', true);
    config()->set('ai_features.rewrite.provider', 'openai');
    config()->set('ai_features.rewrite.model', 'gpt-5.4-mini');
    config()->set('ai_features.rewrite.timeout', 30);
});

test('web ai improve endpoint uses Laravel AI with OpenAI and returns sanitized HTML', function () {
    ContentRewriteAgent::fake([
        '<p>Improved note [[SHIFT_KEEP_1]]</p>',
    ])->preventStrayPrompts();

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>original [[SHIFT_KEEP_1]]</p>',
        'protected_tokens' => ['[[SHIFT_KEEP_1]]'],
        'context' => '1. Alice: prior comment context',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('improved_html', '<p>Improved note [[SHIFT_KEEP_1]]</p>');

    ContentRewriteAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->provider()->name() === 'openai'
            && $prompt->model === 'gpt-5.4-mini'
            && $prompt->timeout === 30
            && $prompt->contains('untrusted application data')
            && $prompt->contains('1. Alice: prior comment context');
    });
});

test('external api ai improve endpoint supports OpenAI through Laravel AI', function () {
    ContentRewriteAgent::fake([
        '<p>Improved via api</p>',
    ])->preventStrayPrompts();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/ai/improve', [
        'project' => $this->project->token,
        'html' => '<p>Original</p>',
        'context' => '1. Bob: external context',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('improved_html', '<p>Improved via api</p>');

    ContentRewriteAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider()->name() === 'openai');
});

test('ai improve sanitizes malicious model output before previewing it', function () {
    ContentRewriteAgent::fake([
        '<p onclick="steal()">Safe text<img src="javascript:alert(1)" onerror="steal()"><script>alert(1)</script><a href="javascript:alert(1)">link</a></p>',
    ])->preventStrayPrompts();

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>Original</p>',
    ]);

    $response->assertOk();

    $html = (string) $response->json('improved_html');
    expect($html)
        ->toContain('<p>Safe text')
        ->toContain('<a>link</a>')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('<script')
        ->not->toContain('javascript:');
});

test('ai improve rejects model output that loses protected tokens without exposing internals', function () {
    ContentRewriteAgent::fake([
        '<p>Token disappeared</p>',
    ])->preventStrayPrompts();

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>Original [[SHIFT_KEEP_1]]</p>',
        'protected_tokens' => ['[[SHIFT_KEEP_1]]'],
    ]);

    $response
        ->assertStatus(422)
        ->assertJsonPath('error', 'Unable to improve message with AI.');
});

test('ai improve endpoints do not expose provider failure details', function () {
    ContentRewriteAgent::fake(function (): never {
        throw new RuntimeException('secret provider response');
    });

    $webResponse = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>Original</p>',
    ]);

    $webResponse
        ->assertStatus(422)
        ->assertJsonPath('error', 'Unable to improve message with AI.')
        ->assertJsonMissing(['error' => 'secret provider response']);

    $apiResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/ai/improve', [
        'project' => $this->project->token,
        'html' => '<p>Original</p>',
    ]);

    $apiResponse
        ->assertStatus(422)
        ->assertJsonPath('error', 'Unable to improve message with AI.')
        ->assertJsonMissing(['error' => 'secret provider response']);
});

test('ai improve validates bounded inputs', function () {
    ContentRewriteAgent::fake()->preventStrayPrompts();

    $response = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => str_repeat('a', 50001),
        'protected_tokens' => array_fill(0, 101, 'token'),
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['html', 'protected_tokens']);

    ContentRewriteAgent::assertNeverPrompted();
});

test('ai improve endpoints return not found when feature is disabled', function () {
    config()->set('ai_features.rewrite.enabled', false);
    ContentRewriteAgent::fake()->preventStrayPrompts();

    $webResponse = $this->actingAs($this->user)->postJson(route('ai.improve'), [
        'html' => '<p>Original</p>',
    ]);

    $webResponse
        ->assertStatus(404)
        ->assertJsonPath('error', 'AI improvement is disabled.');

    $apiResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/ai/improve', [
        'project' => $this->project->token,
        'html' => '<p>Original</p>',
    ]);

    $apiResponse
        ->assertStatus(404)
        ->assertJsonPath('error', 'AI improvement is disabled.');

    ContentRewriteAgent::assertNeverPrompted();
});

test('ai rewrite and email import routes use separate scoped rate limiters', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('ai.improve')?->gatherMiddleware())->toContain('throttle:ai-rewrite')
        ->and($routes->getByName('api.ai.improve')?->gatherMiddleware())->toContain('throttle:ai-rewrite')
        ->and($routes->getByName('tasks.email-import')?->gatherMiddleware())->toContain('throttle:ai-email-import')
        ->and($routes->getByName('api.tasks.email-import')?->gatherMiddleware())->toContain('throttle:ai-email-import');
});
