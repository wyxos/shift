<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    config()->set('shift_ai.enabled', true);
    config()->set('shift_ai.provider', 'ollama');
    config()->set('shift_ai.model', 'gpt-oss:20b');
    config()->set('shift_ai.timeout', 30);
    config()->set('shift_ai.email_import.enabled', true);
    config()->set('shift_ai.email_import.provider', 'ollama');
    config()->set('shift_ai.email_import.model', 'gpt-oss:20b');
    config()->set('shift_ai.email_import.timeout', 30);
    config()->set('ai.providers.ollama.url', 'http://127.0.0.1:11434');
});

test('task email import digests an eml file into reviewable task fields', function () {
    Http::fake([
        'http://127.0.0.1:11434/api/chat' => Http::response([
            'message' => [
                'content' => json_encode([
                    'title' => 'API fails when creating urgent fixes',
                    'priority' => 'High',
                    'description_html' => '<p>Customer reports the urgent fixes API fails during submission.</p><ul><li>Confirm the API response.</li><li>Ask for the failing payload.</li></ul>',
                    'missing_details' => ['Exact request payload', 'Expected response'],
                ]),
            ],
            'done' => true,
        ], 200),
    ]);

    $email = UploadedFile::fake()->createWithContent('issue.eml', implode("\r\n", [
        'Subject: Fw: EXT Urgent Fixes - API question',
        'From: Project Owner <owner@example.com>',
        'To: Joey <joey@example.com>',
        'Date: Wed, 01 Jul 2026 12:00:00 +0400',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'The Voidcare portal user reported that urgent fixes cannot be submitted through the API.',
        'Please check the endpoint and let me know what details are missing.',
    ]));

    $response = $this->actingAs($this->user)->postJson(route('tasks.email-import'), [
        'project_id' => $this->project->id,
        'email' => $email,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.title', 'API fails when creating urgent fixes')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.description_html', '<p>Customer reports the urgent fixes API fails during submission.</p><ul><li>Confirm the API response.</li><li>Ask for the failing payload.</li></ul>')
        ->assertJsonPath('data.missing_details.0', 'Exact request payload')
        ->assertJsonPath('data.source.subject', 'Fw: EXT Urgent Fixes - API question')
        ->assertJsonPath('data.source.from', 'Project Owner <owner@example.com>');

    expect(Task::query()->count())->toBe(0);

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'] ?? [];
        $userPrompt = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

        return str_contains($userPrompt, 'Fw: EXT Urgent Fixes - API question')
            && str_contains($userPrompt, 'urgent fixes cannot be submitted');
    });
});

test('task email import rejects projects the user cannot create tasks for', function () {
    $otherProject = Project::factory()->create();
    $email = UploadedFile::fake()->createWithContent('issue.eml', "Subject: Hidden\r\n\r\nBody");

    $response = $this->actingAs($this->user)->postJson(route('tasks.email-import'), [
        'project_id' => $otherProject->id,
        'email' => $email,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('project_id');

    Http::assertNothingSent();
});
