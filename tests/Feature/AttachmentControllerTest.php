<?php

use App\Enums\OrganisationRole;
use App\Models\Attachment;
use App\Models\Organisation;
use App\Models\OrganisationUser;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    // Create a fake disk for testing
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create([
        'author_id' => $this->user->id,
    ]);

    $this->task = Task::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $this->task->submitter()->associate($this->user)->save();

    $this->tempIdentifier = 'test-'.time();
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
        'url',
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

test('chunked uploads stay bound to their owner and complete successfully', function () {
    $initResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload-init'), [
            'filename' => 'chunked.txt',
            'size' => 5,
            'temp_identifier' => $this->tempIdentifier,
            'mime_type' => 'text/plain',
        ])
        ->assertOk();

    $uploadId = $initResponse->json('upload_id');
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('attachments.upload-status', ['upload_id' => $uploadId]))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->post(route('attachments.upload-chunk'), [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk.part', 'hello'),
        ])
        ->assertOk();

    $completeResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload-complete'), ['upload_id' => $uploadId])
        ->assertOk();

    Storage::assertExists($completeResponse->json('path'));
    Storage::assertExists($completeResponse->json('path').'.meta');
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
                'url',
            ],
        ],
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
    Storage::assertMissing($path.'.meta');
});

test('remove temp rejects arbitrary private and traversal paths', function (string $path) {
    Storage::put('private/protected.txt', 'protected content');

    $this->actingAs($this->user)
        ->delete(route('attachments.remove-temp'), ['path' => $path])
        ->assertBadRequest()
        ->assertJson(['success' => false, 'message' => 'Invalid path']);

    Storage::assertExists('private/protected.txt');
})->with([
    'arbitrary private file' => 'private/protected.txt',
    'normalized traversal' => 'temp_attachments/allowed/../../private/protected.txt',
    'absolute path' => '/temp_attachments/allowed/file.txt',
]);

test('temp files are private to the user who claimed the upload identifier', function () {
    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => UploadedFile::fake()->create('private.png', 10, 'image/png'),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $path = $uploadResponse->json('path');
    $url = $uploadResponse->json('url');
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)->get($url)->assertNotFound();
    $this->actingAs($otherUser)
        ->delete(route('attachments.remove-temp'), ['path' => $path])
        ->assertNotFound();
    $this->actingAs($otherUser)
        ->get(route('attachments.list-temp', ['temp_identifier' => $this->tempIdentifier]))
        ->assertOk()
        ->assertJson(['files' => []]);

    Storage::assertExists($path);
    Storage::assertExists($path.'.meta');
});

test('remove temp rejects metadata paths', function () {
    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => UploadedFile::fake()->create('document.pdf', 10),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $path = $uploadResponse->json('path');

    $this->actingAs($this->user)
        ->delete(route('attachments.remove-temp'), ['path' => $path.'.meta'])
        ->assertBadRequest();

    Storage::assertExists($path);
    Storage::assertExists($path.'.meta');
});

test('remove temp returns not found for an owned path with no file', function () {
    app(\App\Services\TemporaryAttachmentStorage::class)
        ->claim($this->tempIdentifier, $this->user->id);
    $path = "temp_attachments/{$this->tempIdentifier}/missing.pdf";

    $this->actingAs($this->user)
        ->delete(route('attachments.remove-temp'), ['path' => $path])
        ->assertNotFound()
        ->assertJson(['success' => false, 'message' => 'File not found']);
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
        ->postJson(route('tasks.store'), $taskData);

    $response->assertCreated();

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
        ->postJson(route('tasks.store'), $taskData);

    $response->assertCreated();

    // Check that the task was created
    $task = Task::where('title', 'Task without Attachment')->first();
    expect($task)->not->toBeNull();

    // Check that no attachments were created
    $this->assertDatabaseMissing('attachments', [
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
    ]);
});

test('another user cannot promote temporary uploads into their task', function () {
    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => UploadedFile::fake()->create('private.pdf', 10),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create(['author_id' => $otherUser->id]);

    $this->actingAs($otherUser)
        ->postJson(route('tasks.store'), [
            'title' => 'Task without stolen attachment',
            'description' => 'No attachment should be promoted.',
            'project_id' => $otherProject->id,
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertCreated();

    $task = Task::query()->where('title', 'Task without stolen attachment')->firstOrFail();
    expect($task->attachments)->toBeEmpty();
    Storage::assertExists($uploadResponse->json('path'));
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
    $tempIdentifier = 'update-test-'.time();

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
        'priority' => $task->priority,
        'status' => $task->status,
        'temp_identifier' => $tempIdentifier,
        'deleted_attachment_ids' => [$attachment->id],
    ];

    $response = $this->actingAs($this->user)
        ->putJson(route('tasks.update', $task), $updateData);

    $response->assertOk();

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
            ],
        ],
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

test('visible collaborators without task edit permission cannot delete attachments', function () {
    $collaborator = User::factory()->create();
    $this->task->internalCollaborators()->attach($collaborator->id);
    $attachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'protected.pdf',
        'path' => "attachments/{$this->task->id}/protected.pdf",
    ]);
    Storage::put($attachment->path, 'protected content');

    $this->actingAs($collaborator)
        ->delete(route('attachments.delete', $attachment))
        ->assertForbidden();

    $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    Storage::assertExists($attachment->path);
});

test('attachments on hidden tasks remain not found when deletion is attempted', function () {
    $hiddenUser = User::factory()->create();
    $attachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'hidden.pdf',
        'path' => "attachments/{$this->task->id}/hidden.pdf",
    ]);
    Storage::put($attachment->path, 'hidden content');

    $this->actingAs($hiddenUser)
        ->delete(route('attachments.delete', $attachment))
        ->assertNotFound();

    $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    Storage::assertExists($attachment->path);
});

test('task-scope editors can delete attachments', function () {
    $editor = User::factory()->create();
    $organisation = Organisation::factory()->create(['author_id' => $this->user->id]);
    $project = Project::factory()->create([
        'author_id' => $this->user->id,
        'client_id' => null,
        'organisation_id' => $organisation->id,
    ]);
    OrganisationUser::query()->create([
        'organisation_id' => $organisation->id,
        'user_id' => $editor->id,
        'user_email' => $editor->email,
        'user_name' => $editor->name,
        'role' => OrganisationRole::LeadDeveloper->value,
    ]);
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $editor->id,
        'user_email' => $editor->email,
        'user_name' => $editor->name,
        'registration_status' => 'registered',
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $task->submitter()->associate($this->user)->save();
    $attachment = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'editable.pdf',
        'path' => "attachments/{$task->id}/editable.pdf",
    ]);
    Storage::put($attachment->path, 'editable content');

    $this->actingAs($editor)
        ->delete(route('attachments.delete', $attachment))
        ->assertOk();

    $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    Storage::assertMissing($attachment->path);
});

test('show temp serves image inline', function () {
    $uploadResponse = $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => UploadedFile::fake()->create('image.png', 10, 'image/png'),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $response = $this->actingAs($this->user)
        ->get($uploadResponse->json('url'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) Storage::size($uploadResponse->json('path')));

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe(Storage::get($uploadResponse->json('path')));
});

test('download streams files without using a binary file response', function () {
    $attachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'document.pdf',
        'path' => "attachments/{$this->task->id}/document.pdf",
    ]);
    Storage::put($attachment->path, 'document content');

    $response = $this->actingAs($this->user)
        ->get(route('attachments.download', $attachment));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Length', (string) strlen('document content'))
        ->assertDownload('document.pdf');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe('document content');
});

test('download streams images inline without using a binary file response', function () {
    $attachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'image.png',
        'path' => "attachments/{$this->task->id}/image.png",
    ]);
    Storage::put($attachment->path, 'image content');

    $response = $this->actingAs($this->user)
        ->get(route('attachments.download', $attachment));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) strlen('image content'))
        ->assertHeader('Content-Disposition', 'inline; filename=image.png');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe('image content');
});

test('show temp returns 404 for missing file', function () {
    $temp = 'missing-temp';
    $filename = 'nope.png';
    app(\App\Services\TemporaryAttachmentStorage::class)->claim($temp, $this->user->id);

    $response = $this->actingAs($this->user)
        ->get(route('attachments.temp', ['temp' => $temp, 'filename' => $filename]));

    $response->assertStatus(404);
});

test('show temp rejects traversal without serving private storage files', function () {
    Storage::put('private/protected.png', 'protected image');

    $this->actingAs($this->user)
        ->get('/attachments/temp/'.$this->tempIdentifier.'/%2E%2E%2F%2E%2E%2Fprivate%2Fprotected.png')
        ->assertNotFound();

    Storage::assertExists('private/protected.png');
});

test('upload rejects invalid temp identifiers', function (string $tempIdentifier) {
    $this->actingAs($this->user)
        ->post(route('attachments.upload'), [
            'file' => UploadedFile::fake()->create('document.pdf', 10),
            'temp_identifier' => $tempIdentifier,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('temp_identifier');
})->with([
    'traversal' => '../private',
    'nested path' => 'nested/path',
    'absolute path' => '/absolute',
]);
