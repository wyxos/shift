<?php

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

;

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

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'original_filename',
        'path',
        'size',
        'mime_type'
    ]);

    // Verify the file was stored
    $path = $response->json('path');
    Storage::assertExists($path);

    // Verify metadata was stored
    Storage::assertExists($path . '.meta');
    $metadata = json_decode(Storage::get($path . '.meta'), true);
    expect($metadata['original_filename'])->toEqual('document.pdf');
});

test('upload validates required fields', function () {
    // Test missing file
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);

    // Test missing temp_identifier
    $file = UploadedFile::fake()->create('document.pdf', 1000);
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'file' => $file,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['temp_identifier']);
});

test('upload validates file size', function () {
    // Create a file larger than the 10MB limit
    $file = UploadedFile::fake()->create('large-document.pdf', 11000);

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);
});

test('upload multiple stores files successfully', function () {
    $file1 = UploadedFile::fake()->create('document1.pdf', 1000);
    $file2 = UploadedFile::fake()->create('document2.pdf', 1000);

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
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
                'mime_type'
            ]
        ]
    ]);

    // Verify the files were stored
    expect($response->json('files'))->toHaveCount(2);

    foreach ($response->json('files') as $file) {
        Storage::assertExists($file['path']);
        Storage::assertExists($file['path'] . '.meta');
    }
});

test('upload multiple validates required fields', function () {
    // Test missing attachments
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->withHeader('Accept', 'application/json')
        ->post(route('api.attachments.upload-multiple'), [
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attachments']);

    // Test missing temp_identifier
    $file = UploadedFile::fake()->create('document.pdf', 1000);
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
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
    $uploadResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $path = $uploadResponse->json('path');

    // Verify the file exists
    Storage::assertExists($path);
    Storage::assertExists($path . '.meta');

    // Now remove the file
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->delete(route('api.attachments.remove-temp'), [
            'path' => $path,
        ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'File removed successfully']);

    // Verify the file and metadata were deleted
    Storage::assertMissing($path);
    Storage::assertMissing($path . '.meta');
});

test('remove temp validates path', function () {
    // Test invalid path (not starting with temp_attachments/)
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->delete(route('api.attachments.remove-temp'), [
            'path' => 'invalid/path/file.pdf',
        ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Invalid path']);
});

test('remove temp handles missing file', function () {
    $nonExistentPath = "temp_attachments/{$this->tempIdentifier}/non-existent-file.pdf";

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
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

    $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file1,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->post(route('api.attachments.upload'), [
            'file' => $file2,
            'temp_identifier' => $this->tempIdentifier,
        ]);

    // List the files
    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
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
                'mime_type'
            ]
        ]
    ]);

    // Verify we got both files
    expect($response->json('files'))->toHaveCount(2);
});

test('list temp returns empty array for nonexistent directory', function () {
    $nonExistentIdentifier = 'non-existent-identifier';

    $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
        ->get(route('api.attachments.list-temp', [
            'temp_identifier' => $nonExistentIdentifier,
        ]));

    $response->assertStatus(200);
    $response->assertJson(['files' => []]);
});

test('download returns file for valid attachment', function () {
    $response = $this->actingAs($this->user)
        ->get(route('api.attachments.download', $this->attachment));

    $response->assertStatus(200);
    // Storage::response returns the file's actual MIME type
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('download returns error for missing file', function () {
    // Delete the file but keep the attachment record
    Storage::delete($this->attachment->path);

    $response = $this->actingAs($this->user)
        ->get(route('api.attachments.download', $this->attachment));

    // Current controller uses Storage::response which throws if the file is missing.
    // Assert we get a non-success response (server error). If behavior changes to 404 later,
    // this assertion can be tightened.
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

    $response = $this->actingAs($this->user)
        ->get(route('api.attachments.download', $imageAttachment));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'image/jpeg');
});
