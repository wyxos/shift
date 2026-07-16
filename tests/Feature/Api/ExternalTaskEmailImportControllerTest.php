<?php

use App\Ai\Agents\TaskEmailImportAgent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;
    $this->project = Project::factory()->create([
        'author_id' => $this->user->id,
        'token' => 'test-project-token',
    ]);

    config()->set('ai_features.email_import.enabled', true);
    config()->set('ai_features.email_import.provider', 'openai');
    config()->set('ai_features.email_import.model', 'gpt-5.4-mini');
    config()->set('ai_features.email_import.timeout', 30);
});

test('external task email import supports OpenAI through Laravel AI', function () {
    TaskEmailImportAgent::fake([[
        'title' => 'API fails when creating urgent fixes',
        'priority' => 'High',
        'description_html' => '<p>Customer reports the urgent fixes API fails during submission.</p>',
        'missing_details' => ['Exact request payload'],
    ]])->preventStrayPrompts();

    $email = UploadedFile::fake()->createWithContent('issue.eml', implode("\r\n", [
        'Subject: Fw: EXT Urgent Fixes - API question',
        'From: Project Owner <owner@example.com>',
        'To: Joey <joey@example.com>',
        'Date: Wed, 01 Jul 2026 12:00:00 +0400',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'The Voidcare portal user reported that urgent fixes cannot be submitted through the API.',
    ]));

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Accept' => 'application/json',
    ])->post('/api/tasks/email-import', [
        'project' => $this->project->token,
        'user' => [
            'id' => 'ext-123',
            'name' => 'External User',
            'email' => 'external@example.com',
            'environment' => 'testing',
            'url' => 'https://example.com',
        ],
        'metadata' => [
            'url' => 'https://example.com/tasks/new',
            'environment' => 'testing',
        ],
        'email' => $email,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.title', 'API fails when creating urgent fixes')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.description_html', '<p>Customer reports the urgent fixes API fails during submission.</p>')
        ->assertJsonPath('data.missing_details.0', 'Exact request payload')
        ->assertJsonPath('data.source.subject', 'Fw: EXT Urgent Fixes - API question');

    expect(Task::query()->count())->toBe(0);

    TaskEmailImportAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider()->name() === 'openai');
});

test('external task email import returns 404 for a project the api user cannot access', function () {
    TaskEmailImportAgent::fake()->preventStrayPrompts();
    $otherProject = Project::factory()->create(['token' => 'other-project-token']);
    $email = UploadedFile::fake()->createWithContent('issue.eml', "Subject: Hidden\r\n\r\nBody");

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->token,
        'Accept' => 'application/json',
    ])->post('/api/tasks/email-import', [
        'project' => $otherProject->token,
        'email' => $email,
    ]);

    $response
        ->assertNotFound()
        ->assertJsonPath('error', 'Project not found');

    TaskEmailImportAgent::assertNeverPrompted();
});
