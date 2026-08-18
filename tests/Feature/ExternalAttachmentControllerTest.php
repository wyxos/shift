<?php

use App\Enums\ExternalUserRole;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shift\Core\ChunkedUploadConfig;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    // Create a fake disk for testing
    Storage::fake('local');

    // Create a user
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-token')->plainTextToken;

    // Create a task
    $this->task = Task::factory()->create();
    $this->task->submitter()->associate($this->user)->save();

    // Create an attachment for the task
    $this->attachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-document.pdf',
        'path' => "attachments/{$this->task->id}/test-document.pdf",
    ]);

    // Create a fake file in the storage
    Storage::put($this->attachment->path, 'test content');

    // Generate a temp identifier for uploads
    $this->tempIdentifier = Str::random(10);
});

test('upload stores file successfully', function () {
    $file = UploadedFile::fake()->create('document.pdf', 1000);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'original_filename',
        'path',
        'size',
        'mime_type',
    ]);

    // Verify the file was stored
    $path = $response->json('path');
    Storage::assertExists($path);

    // Verify metadata was stored
    Storage::assertExists($path.'.meta');
    $metadata = json_decode(Storage::get($path.'.meta'), true);
    expect($metadata['original_filename'])->toEqual('document.pdf');
});

test('upload validates required fields', function () {
    // Test missing file
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);

    // Test missing temp_identifier
    $file = UploadedFile::fake()->create('document.pdf', 1000);
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'file' => $file,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['temp_identifier']);
});

test('upload validates file size', function () {
    // Create a file larger than the configured max upload size
    $file = UploadedFile::fake()->create('large-document.pdf', ChunkedUploadConfig::MAX_UPLOAD_KB + 1);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('chunked API uploads stay bound to their token owner and complete successfully', function () {
    $initResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload-init'), [
            'filename' => 'chunked.txt',
            'size' => 5,
            'temp_identifier' => $this->tempIdentifier,
            'mime_type' => 'text/plain',
        ])
        ->assertOk();

    $uploadId = $initResponse->json('upload_id');
    $otherUser = User::factory()->create();
    $otherToken = $otherUser->createToken('other-chunk-token')->plainTextToken;
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->get(route('api.attachments.upload-status', ['upload_id' => $uploadId]))
        ->assertNotFound();

    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload-chunk'), [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk.part', 'hello'),
        ])
        ->assertOk();

    $completeResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload-complete'), ['upload_id' => $uploadId])
        ->assertOk();

    Storage::assertExists($completeResponse->json('path'));
    Storage::assertExists($completeResponse->json('path').'.meta');
});

test('upload multiple stores files successfully', function () {
    $file1 = UploadedFile::fake()->create('document1.pdf', 1000);
    $file2 = UploadedFile::fake()->create('document2.pdf', 1000);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload-multiple'), [
            'attachments' => [$file1, $file2],
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'files' => [
            '*' => [
                'original_filename',
                'path',
                'size',
                'mime_type',
            ],
        ],
    ]);

    // Verify the files were stored
    expect($response->json('files'))->toHaveCount(2);

    foreach ($response->json('files') as $file) {
        Storage::assertExists($file['path']);
        Storage::assertExists($file['path'].'.meta');
    }
});

test('upload multiple validates required fields', function () {
    // Test missing attachments
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload-multiple'), [
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attachments']);

    // Test missing temp_identifier
    $file = UploadedFile::fake()->create('document.pdf', 1000);
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload-multiple'), [
            'attachments' => [$file],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['temp_identifier']);
});

test('remove temp deletes file successfully', function () {
    // First upload a file
    $file = UploadedFile::fake()->create('document.pdf', 1000);
    $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $path = $uploadResponse->json('path');

    // Verify the file exists
    Storage::assertExists($path);
    Storage::assertExists($path.'.meta');

    // Now remove the file
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->delete(route('api.attachments.remove-temp'), [
            'path' => $path,
        ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'File removed successfully']);

    // Verify the file and metadata were deleted
    Storage::assertMissing($path);
    Storage::assertMissing($path.'.meta');
});

test('remove temp validates path', function () {
    // Test invalid path (not starting with temp_attachments/)
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->delete(route('api.attachments.remove-temp'), [
            'path' => 'invalid/path/file.pdf',
        ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid path']);
});

test('remove temp rejects arbitrary private and traversal paths', function (string $path) {
    Storage::put('private/protected.txt', 'protected content');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->delete(route('api.attachments.remove-temp'), ['path' => $path])
        ->assertBadRequest()
        ->assertJson(['error' => 'Invalid path']);

    Storage::assertExists('private/protected.txt');
})->with([
    'arbitrary private file' => 'private/protected.txt',
    'normalized traversal' => 'temp_attachments/allowed/../../private/protected.txt',
    'absolute path' => '/temp_attachments/allowed/file.txt',
]);

test('temporary API attachments are private to the authenticated uploader', function () {
    $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => UploadedFile::fake()->create('private.png', 10, 'image/png'),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $path = $uploadResponse->json('path');
    $filename = basename($path);
    $otherUser = User::factory()->create();
    $otherToken = $otherUser->createToken('other-temp-token')->plainTextToken;
    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->get(route('api.attachments.temp', [
            'temp' => $this->tempIdentifier,
            'filename' => $filename,
        ]))
        ->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->delete(route('api.attachments.remove-temp'), ['path' => $path])
        ->assertNotFound();
    $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->get(route('api.attachments.list-temp', ['temp_identifier' => $this->tempIdentifier]))
        ->assertOk()
        ->assertJson(['files' => []]);

    Storage::assertExists($path);
    Storage::assertExists($path.'.meta');
});

test('remove temp rejects metadata paths', function () {
    $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => UploadedFile::fake()->create('document.pdf', 10),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $path = $uploadResponse->json('path');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->delete(route('api.attachments.remove-temp'), ['path' => $path.'.meta'])
        ->assertBadRequest();

    Storage::assertExists($path);
    Storage::assertExists($path.'.meta');
});

test('remove temp handles missing file', function () {
    app(\App\Services\TemporaryAttachmentStorage::class)
        ->claim($this->tempIdentifier, $this->user->id);
    $nonExistentPath = "temp_attachments/{$this->tempIdentifier}/non-existent-file.pdf";

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->delete(route('api.attachments.remove-temp'), [
            'path' => $nonExistentPath,
        ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'File not found']);
});

test('list temp returns files', function () {
    // Upload a couple of files
    $file1 = UploadedFile::fake()->create('document1.pdf', 1000);
    $file2 = UploadedFile::fake()->create('document2.pdf', 1000);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file1,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file2,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    // List the files
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.list-temp', [
            'temp_identifier' => $this->tempIdentifier,
        ]));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'files' => [
            '*' => [
                'original_filename',
                'path',
                'size',
                'mime_type',
            ],
        ],
    ]);

    // Verify we got both files
    expect($response->json('files'))->toHaveCount(2);
});

test('list temp returns empty array for nonexistent directory', function () {
    $nonExistentIdentifier = 'non-existent-identifier';

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.list-temp', [
            'temp_identifier' => $nonExistentIdentifier,
        ]));

    $response->assertStatus(200);
    $response->assertJson(['files' => []]);
});

test('show temp serves an owned API attachment', function () {
    $uploadResponse = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->post(route('api.attachments.upload'), [
            'file' => UploadedFile::fake()->create('image.png', 10, 'image/png'),
            'temp_identifier' => $this->tempIdentifier,
        ])
        ->assertOk();

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.temp', [
            'temp' => $this->tempIdentifier,
            'filename' => basename($uploadResponse->json('path')),
        ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) Storage::size($uploadResponse->json('path')));

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe(Storage::get($uploadResponse->json('path')));
});

test('show temp rejects traversal without serving private storage files', function () {
    Storage::put('private/protected.png', 'protected image');

    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get('/api/attachments/temp/'.$this->tempIdentifier.'/%2E%2E%2F%2E%2E%2Fprivate%2Fprotected.png')
        ->assertNotFound();

    Storage::assertExists('private/protected.png');
});

test('API uploads reject invalid temp identifiers', function (string $tempIdentifier) {
    $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
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

test('download returns file for valid attachment', function () {
    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.download', $this->attachment));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Length', (string) strlen('test content'))
        ->assertDownload('test-document.pdf');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe('test content');
});

test('download returns error for missing file', function () {
    // Delete the file but keep the attachment record
    Storage::delete($this->attachment->path);

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.download', $this->attachment));

    // Assert we get a non-success response when the file is missing.
    expect($response->status())->toBeGreaterThanOrEqual(400);
});

test('download returns image inline for image files', function () {
    // Create an image attachment
    $imageAttachment = Attachment::create([
        'attachable_id' => $this->task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'test-image.jpg',
        'path' => "attachments/{$this->task->id}/test-image.jpg",
    ]);

    // Create a fake image file
    Storage::put($imageAttachment->path, 'fake image content');

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.download', $imageAttachment));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Length', (string) strlen('fake image content'))
        ->assertHeader('Content-Disposition', 'inline; filename=test-image.jpg');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->streamedContent())->toBe('fake image content');
});

test('external role-visible users can download task attachments', function () {
    $project = Project::factory()->create([
        'token' => 'attachment-role-project',
        'author_id' => $this->user->id,
    ]);
    $owner = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'owner-1',
        'environment' => 'testing',
        'url' => 'https://consumer.test',
        'role' => ExternalUserRole::Owner,
    ]);
    $shiftLeadDeveloper = ExternalUser::factory()->create([
        'project_id' => $project->id,
        'external_id' => 'shift-lead-1',
        'environment' => 'testing',
        'url' => 'https://consumer.test',
        'role' => ExternalUserRole::ShiftLeadDeveloper,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
    ]);
    $task->submitter()->associate($owner)->save();
    $attachment = Attachment::create([
        'attachable_id' => $task->id,
        'attachable_type' => Task::class,
        'original_filename' => 'role-visible-document.pdf',
        'path' => "attachments/{$task->id}/role-visible-document.pdf",
    ]);
    Storage::put($attachment->path, 'role visible content');

    $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
        ->get(route('api.attachments.download', [
            'attachment' => $attachment,
            'project' => $project->token,
            'user' => [
                'id' => $shiftLeadDeveloper->external_id,
                'environment' => $shiftLeadDeveloper->environment,
                'url' => $shiftLeadDeveloper->url,
            ],
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('download requires authentication', function () {
    $response = $this->getJson(route('api.attachments.download', $this->attachment));

    $response->assertUnauthorized();
});

test('download returns not found for users without task access', function () {
    $otherUser = User::factory()->create();
    $otherToken = $otherUser->createToken('other-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$otherToken)
        ->get(route('api.attachments.download', $this->attachment));

    $response->assertNotFound();
});
