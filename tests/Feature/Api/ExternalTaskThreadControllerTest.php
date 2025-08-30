<?php

use App\Models\ExternalUser;
use App\Models\TaskThread;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\UploadedFile;

;

beforeEach(function () {
    // Create a project with an API token
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create([
        'author_id' => $this->user->id
    ]);
    $this->project->generateApiToken();
    $this->token = $this->user->createToken('test-token')->plainTextToken;

    // Create an external user
    $this->externalUser = ExternalUser::factory()->create([
        'external_id' => 'ext-123',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'name' => 'External Test User',
        'email' => 'external@example.com',
    ]);

    $this->externalUserData = [
        'id' => $this->externalUser->external_id,
        'environment' => $this->externalUser->environment,
        'url' => $this->externalUser->url,
        'name' => $this->externalUser->name,
        'email' => $this->externalUser->email,
    ];

    // Create a task
    $this->task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Test Task',
    ]);
    $this->task->submitter()->associate($this->externalUser)->save();
});


test('external thread with 2 embedded images and 1 non-embedded PDF returns only PDF in attachments list', function () {
    Storage::fake('local');

    $tempIdentifier = 'thread-' . time();
    // Create temp files for two images and one pdf
    $img1 = 'img_' . uniqid() . '.png';
    $img2 = 'img_' . uniqid() . '.jpg';
    $pdf1 = 'file_' . uniqid() . '.pdf';
    $tempDir = 'temp_attachments/' . $tempIdentifier;
    Storage::put($tempDir . '/' . $img1, 'fake');
    Storage::put($tempDir . '/' . $img1 . '.meta', json_encode(['original_filename' => 'photo1.png']));
    Storage::put($tempDir . '/' . $img2, 'fake');
    Storage::put($tempDir . '/' . $img2 . '.meta', json_encode(['original_filename' => 'photo2.jpg']));
    Storage::put($tempDir . '/' . $pdf1, 'fake');
    Storage::put($tempDir . '/' . $pdf1 . '.meta', json_encode(['original_filename' => 'doc.pdf']));

    // Content embeds only the two image temp URLs
    $content = '<p>Here are two images:</p>'
        . '<p><img src="/attachments/temp/' . $tempIdentifier . '/' . $img1 . '"></p>'
        . '<p><img src="/attachments/temp/' . $tempIdentifier . '/' . $img2 . '"></p>';

    $threadData = [
        'content' => $content,
        'type' => 'external',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
        'temp_identifier' => $tempIdentifier,
    ];

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->postJson("/api/tasks/{$this->task->id}/threads", $threadData);

    $response->assertStatus(201);

    $finalContent = $response->json('thread.content');
    $threadId = $response->json('thread.id');
    expect($finalContent)->not->toContain('/attachments/temp/');

    // Content URLs should be rewritten to signed thread asset URLs for images
    // Expect URLs like /attachments/task_threads/{threadId}/{filename}?expiration=...
    expect($finalContent)->toContain('/attachments/task_threads/' . $threadId . '/');

    // Only the PDF should remain in attachments array
    $attachments = $response->json('thread.attachments');
    expect($attachments)->toBeArray();
    expect(count($attachments))->toBe(1);
    expect($attachments[0]['original_filename'])->toBe('doc.pdf');
});

test('external thread creation sends notifications to project users', function () {
    Notification::fake();

    // Create additional users with access to the project
    $projectUser1 = User::factory()->create();
    $projectUser2 = User::factory()->create();

    // Give these users access to the project
    ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $projectUser1->id,
        'user_email' => $projectUser1->email,
        'user_name' => $projectUser1->name,
        'registration_status' => 'registered'
    ]);

    ProjectUser::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $projectUser2->id,
        'user_email' => $projectUser2->email,
        'user_name' => $projectUser2->name,
        'registration_status' => 'registered'
    ]);

    $threadData = [
        'content' => 'This is a test message from an external user',
        'type' => 'external',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
    ];

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->postJson("/api/tasks/{$this->task->id}/threads", $threadData);

    $response->assertStatus(201);

    // Assert that notifications were sent to all project users
Notification::assertSentTo(
        [$this->user, $projectUser1, $projectUser2],
        TaskThreadUpdated::class
    );
});


test('external API thread replaces temp URLs in content with final download URLs', function () {
    Storage::fake('local');

    $tempIdentifier = 'thread-' . time();
    // Create a temp file and metadata
    $storedFilename = 'img_' . uniqid() . '.png';
    $tempDir = 'temp_attachments/' . $tempIdentifier;
    Storage::put($tempDir . '/' . $storedFilename, 'fake');
    Storage::put($tempDir . '/' . $storedFilename . '.meta', json_encode(['original_filename' => 'photo.png']));

    $content = '<p><img src="/attachments/temp/' . $tempIdentifier . '/' . $storedFilename . '"></p>';

    $threadData = [
        'content' => $content,
        'type' => 'external',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
        'temp_identifier' => $tempIdentifier,
    ];

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->postJson("/api/tasks/{$this->task->id}/threads", $threadData);

    $response->assertStatus(201);

    $out = $response->json('thread.content');
    $threadId = $response->json('thread.id');

    // Attachment IDs are no longer used in embedded content URLs for API threads.
    expect($out)->not->toContain('/attachments/temp/');
    expect($out)->toContain('/attachments/task_threads/' . $threadId . '/');

    // Embedded image should be excluded from attachments list in response
    expect($response->json('thread.attachments'))->toBeArray()->toBeEmpty();
});


test('external API thread replaces encoded-space temp URLs with final API download URLs', function () {
    Storage::fake('local');

    $tempIdentifier = 'thread-' . time();
    // Create a temp file whose basename contains spaces
    $storedFilename = 'Proof of Address 20252707_' . uniqid() . '.jpg';
    $tempDir = 'temp_attachments/' . $tempIdentifier;
    Storage::put($tempDir . '/' . $storedFilename, 'fake');
    Storage::put($tempDir . '/' . $storedFilename . '.meta', json_encode(['original_filename' => 'Proof of Address 20252707.jpg']));

    // Build the absolute temp URL (route helper will URL-encode spaces)
    $tempUrl = route('attachments.temp', ['temp' => $tempIdentifier, 'filename' => $storedFilename]);

    $threadData = [
        'content' => '<p><img src="' . $tempUrl . '"></p>',
        'type' => 'external',
        'project' => $this->project->token,
        'user' => $this->externalUserData,
        'temp_identifier' => $tempIdentifier,
    ];

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->postJson("/api/tasks/{$this->task->id}/threads", $threadData);

    $response->assertStatus(201);

    $out = $response->json('thread.content');
    $threadId = $response->json('thread.id');

    // Embedded content should reference the signed thread asset URL and not temp URLs
    expect($out)->not->toContain('/attachments/temp/');
    expect($out)->toContain('/attachments/task_threads/' . $threadId . '/');
    expect($response->json('thread.attachments'))->toBeArray()->toBeEmpty();
});
