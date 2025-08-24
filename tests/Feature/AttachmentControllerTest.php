<?php

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

;

beforeEach(function () {
    // Create a fake disk for testing
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create([
        'author_id' => $this->user->id
    ]);

    $this->task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $this->task->submitter()->associate($this->user)->save();

    $this->tempIdentifier = 'test-' . time();
});

test('upload stores file in temp folder', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'original_filename',
        'path',
        'url'
    ]);

    // Check that the file exists in the temp folder
    $path = $response->json('path');
    Storage::assertExists($path);

    // Check that the filename is correct
    expect($response->json('original_filename'))->toEqual('document.pdf');
});

test('upload fails without file', function () {
    $response = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('upload fails without temp identifier', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['temp_identifier']);
});

test('list temp files', function () {
    // Upload a file first
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    // Now list the files
    $response = $this->actingAs($this->user)
        ->get(route('attachments.list-temp', [
            'temp_identifier' => $this->tempIdentifier,
        ]));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'files' => [
            '*' => [
                'path',
                'original_filename',
                'url'
            ]
        ]
    ]);

    expect($response->json('files'))->toHaveCount(1);
    expect($response->json('files.0.original_filename'))->toEqual('document.pdf');
});

test('remove temp file', function () {
    // Upload a file first
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $path = $uploadResponse->json('path');

    // Now remove the file
    $response = $this->actingAs($this->user)
        ->delete(route('attachments.remove-temp'), [
            'path' => $path,
        ]);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    // Check that the file no longer exists
    Storage::assertMissing($path);
});

test('task creation with attachments', function () {
    // Upload a file first
    $file = UploadedFile::fake()->create('document.pdf', 100);

    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $path = $uploadResponse->json('path');

    // Now create a task with the temp_identifier
    $taskData = [
        'title' => 'Task with Attachment',
        'description' => 'This task has an attachment',
        'project_id' => $this->project->id,
        'temp_identifier' => $this->tempIdentifier,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('tasks.store'), $taskData);

    $response->assertRedirect(route('tasks.index'));

    // Check that the task was created
    $task = Task::where('title', 'Task with Attachment')->first();
    expect($task)->not->toBeNull();

    // Check that the attachment was created
    $this->assertDatabaseHas('attachments', [
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'document.pdf',
    ]);

    // Check that the file was moved to the permanent location
    $attachment = Attachment::where('attachable_id', $task->id)
        ->where('attachable_type', Task::class)
        ->first();
    Storage::assertExists($attachment->path);

    // Check that the temp file was deleted
    Storage::assertMissing($path);
});

test('task creation without attachments', function () {
    $taskData = [
        'title' => 'Task without Attachment',
        'description' => 'This task has no attachment',
        'project_id' => $this->project->id,
    ];

    $response = $this->actingAs($this->user)
        ->post(route('tasks.store'), $taskData);

    $response->assertRedirect(route('tasks.index'));

    // Check that the task was created
    $task = Task::where('title', 'Task without Attachment')->first();
    expect($task)->not->toBeNull();

    // Check that no attachments were created
    $this->assertDatabaseMissing('attachments', [
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
    ]);
});

test('task update with attachments', function () {
    // Create a task with an attachment
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'Original Task Title',
    ]);
    $task->submitter()->associate($this->user)->save();

    // Create an attachment for the task
    $attachment = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'existing-document.pdf',
        'path' => "attachments/{$task->id}/existing-document.pdf",
    ]);

    // Create a fake file in the storage
    Storage::put($attachment->path, 'test content');

    // Upload a new file to temp storage
    $file = UploadedFile::fake()->create('new-document.pdf', 100);
    $tempIdentifier = 'update-test-' . time();

    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $tempIdentifier,
        ]);

    $tempPath = $uploadResponse->json('path');

    // Update the task with new attachment and delete the existing one
    $updateData = [
        'title' => 'Updated Task Title',
        'description' => 'Updated description',
        'temp_identifier' => $tempIdentifier,
        'deleted_attachment_ids' => [$attachment->id],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('tasks.update', $task), $updateData);

    $response->assertRedirect(route('tasks.index'));

    // Check that the task was updated
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'title' => 'Updated Task Title',
        'description' => 'Updated description',
    ]);

    // Check that the old attachment was deleted
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment->id,
    ]);
    Storage::assertMissing($attachment->path);

    // Check that a new attachment was created
    $newAttachment = Attachment::where('attachable_id', $task->id)
        ->where('attachable_type', Task::class)
        ->first();
    expect($newAttachment)->not->toBeNull();
    expect($newAttachment->original_filename)->toEqual('new-document.pdf');
    Storage::assertExists($newAttachment->path);

    // Check that the temp file was deleted
    Storage::assertMissing($tempPath);
});

test('list task attachments', function () {
    // Create a task with attachments
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    // Create attachments for the task
    $attachment1 = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'document1.pdf',
        'path' => "attachments/{$task->id}/document1.pdf",
    ]);

    $attachment2 = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'document2.pdf',
        'path' => "attachments/{$task->id}/document2.pdf",
    ]);

    // Create fake files in the storage
    Storage::put($attachment1->path, 'test content 1');
    Storage::put($attachment2->path, 'test content 2');

    // Get the list of attachments
    $response = $this->actingAs($this->user)
        ->get(route('attachments.list-task', $task));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'attachments' => [
            '*' => [
                'id',
                'original_filename',
                'path',
                'url',
                'created_at',
            ]
        ]
    ]);

    expect($response->json('attachments'))->toHaveCount(2);
    expect($response->json('attachments.0.original_filename'))->toEqual('document1.pdf');
    expect($response->json('attachments.1.original_filename'))->toEqual('document2.pdf');
});

test('delete attachment', function () {
    // Create a task with an attachment
    $task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $task->submitter()->associate($this->user)->save();

    // Create an attachment for the task
    $attachment = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'document.pdf',
        'path' => "attachments/{$task->id}/document.pdf",
    ]);

    // Create a fake file in the storage
    Storage::put($attachment->path, 'test content');

    // Delete the attachment
    $response = $this->actingAs($this->user)
        ->delete(route('attachments.delete', $attachment));

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    // Check that the attachment was deleted from the database
    $this->assertDatabaseMissing('attachments', [
        'id' => $attachment->id,
    ]);

    // Check that the file was deleted from storage
    Storage::assertMissing($attachment->path);
});

test('show temp serves image inline', function () {
    $temp = 'temp-' . time();
    $filename = 'image.png';
    $path = "temp_attachments/{$temp}/{$filename}";

    Storage::put($path, 'fake-image-content');

    $response = $this->actingAs($this->user)
        ->get(route('attachments.temp', ['temp' => $temp, 'filename' => $filename]));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/png');
});

test('show temp returns 404 for missing file', function () {
    $temp = 'missing-temp';
    $filename = 'nope.png';

    $response = $this->actingAs($this->user)
        ->get(route('attachments.temp', ['temp' => $temp, 'filename' => $filename]));

    $response->assertStatus(404);
});
