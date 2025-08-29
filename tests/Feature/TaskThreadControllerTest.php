<?php

use App\Models\ExternalUser;
use App\Models\TaskThread;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

;

beforeEach(function () {
    // Create a user
    $this->user = User::factory()->create();

    // Create an external user
    $this->externalUser = ExternalUser::factory()->create([
        'external_id' => 'ext-123',
        'environment' => 'testing',
        'url' => 'https://example.com',
        'name' => 'External Test User',
        'email' => 'external@example.com',
    ]);

    // Create a task submitted by the external user
    $this->task = Task::factory()->create();
    $this->task->submitter()->associate($this->externalUser)->save();
});


test('internal thread with 2 embedded images and 1 non-embedded PDF returns only PDF in attachments list', function () {
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

    // Create thread
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => $content,
            'type' => 'internal',
            'temp_identifier' => $tempIdentifier,
        ]);

    $response->assertStatus(201);

    // Verify content has final download URLs, not temp
    $finalContent = $response->json('thread.content');
    expect($finalContent)->not->toContain('/attachments/temp/');

    // Only the PDF should remain in attachments array
    $attachments = $response->json('thread.attachments');
    expect($attachments)->toBeArray();
    expect(count($attachments))->toBe(1);
    expect($attachments[0]['original_filename'])->toBe('doc.pdf');
});

test('external thread creation sends notification to external user in non production', function () {
    Notification::fake();

    // Mock the HTTP call to the external system
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => false // This will trigger the notification
        ], 200)
    ]);

    // Create a thread message as the authenticated user
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => 'This is a test message',
            'type' => 'external',
        ]);

    $response->assertStatus(201);

    // Assert that a notification was sent to the external user
    Notification::assertSentOnDemand(
        TaskThreadUpdated::class,
        function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === $this->externalUser->email;
        }
    );
});

test('external thread creation does not send notification in production', function () {
    Notification::fake();

    // Mock the HTTP call to the external system
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => true // This will prevent the notification
        ], 200)
    ]);

    // Create a thread message as the authenticated user
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => 'This is a test message',
            'type' => 'external',
        ]);

    $response->assertStatus(201);

    // Assert that no notification was sent
    Notification::assertNothingSent();
});

test('internal thread creation does not send notification', function () {
    Notification::fake();

    // Create a thread message as the authenticated user
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => 'This is an internal test message',
            'type' => 'internal',
        ]);

    $response->assertStatus(201);

    // Assert that no notification was sent
    Notification::assertNothingSent();
});

test('external thread creation with non external submitter sends notification to external users', function () {
    Notification::fake();

    // Create a task submitted by a regular user (not an external user)
    $regularUser = User::factory()->create();
    $task = Task::factory()->create();
    $task->submitter()->associate($regularUser)->save();

    // Add an external user with access to the task
    $task->externalUsers()->attach($this->externalUser);

    // Mock the HTTP call to the external system
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => false // This will trigger the notification
        ], 200)
    ]);

    // Create a thread message as the authenticated user
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $task), [
            'content' => 'This is a test message',
            'type' => 'external',
        ]);

    $response->assertStatus(201);

    // Assert that a notification was sent to the external user
    Notification::assertSentOnDemand(
        TaskThreadUpdated::class,
        function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === $this->externalUser->email;
        }
    );
});

test('external thread creation sends notification to multiple external users', function () {
    Notification::fake();

    // Create another external user
    $anotherExternalUser = ExternalUser::factory()->create([
        'external_id' => 'ext-456',
        'environment' => 'testing',
        'url' => 'https://another-example.com',
        'name' => 'Another External User',
        'email' => 'another-external@example.com',
    ]);

    // Add both external users to the task
    $this->task->externalUsers()->attach([$this->externalUser->id, $anotherExternalUser->id]);

    // Mock the HTTP calls to both external systems
    Http::fake([
        'https://example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => false // This will trigger the notification
        ], 200),
        'https://another-example.com/shift/api/notifications' => Http::response([
            'success' => true,
            'production' => false // This will trigger the notification
        ], 200)
    ]);

    // Create a thread message as the authenticated user
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => 'This is a test message',
            'type' => 'external',
        ]);

    $response->assertStatus(201);

    // Assert that notifications were sent to both external users
    Notification::assertSentOnDemand(
        TaskThreadUpdated::class,
        function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === $this->externalUser->email;
        }
    );

Notification::assertSentOnDemand(
        TaskThreadUpdated::class,
        function ($notification, $channels, $notifiable) use ($anotherExternalUser) {
            return $notifiable->routes['mail'] === $anotherExternalUser->email;
        }
    );
});


test('internal thread replaces temp URLs in content with final download URLs', function () {
    Storage::fake('local');

    $tempIdentifier = 'thread-' . time();
    $file = UploadedFile::fake()->image('photo.png');

    // Upload to temp storage
    $upload = $this->actingAs($this->user)->post(route('attachments.upload'), [
        'file' => $file,
        'temp_identifier' => $tempIdentifier,
    ]);
    $upload->assertStatus(200);

    $tempUrl = $upload->json('url');

    // Create thread with content embedding the temp URL
    $response = $this->actingAs($this->user)
        ->postJson(route('task-threads.store', $this->task), [
            'content' => '<p><img src="' . $tempUrl . '"></p>',
            'type' => 'internal',
            'temp_identifier' => $tempIdentifier,
        ]);

    $response->assertStatus(201);

    $content = $response->json('thread.content');
    $threadId = $response->json('thread.id');

    // Fetch the persisted attachment ID from the database
    $thread = TaskThread::find($threadId);
    $attachmentId = optional($thread->attachments()->first())->id;

    expect($content)->not->toContain('/attachments/temp/');
    expect($content)->toContain('/attachments/' . $attachmentId . '/download');

    // Since image is embedded in content, attachments list in response should exclude it
    expect($response->json('thread.attachments'))->toBeArray()->toBeEmpty();
});
