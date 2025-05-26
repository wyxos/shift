<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Task $task;
    protected Project $project;
    protected string $tempIdentifier;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_upload_stores_file_in_temp_folder()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
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
        $this->assertEquals('document.pdf', $response->json('original_filename'));
    }

    public function test_upload_fails_without_file()
    {
        $response = $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
                'temp_identifier' => $this->tempIdentifier,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_upload_fails_without_temp_identifier()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
                'file' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['temp_identifier']);
    }

    public function test_list_temp_files()
    {
        // Upload a file first
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
                'file' => $file,
                'temp_identifier' => $this->tempIdentifier,
            ]);

        // Now list the files
        $response = $this->actingAs($this->user)
            ->get(route('task-attachments.list-temp', [
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

        $this->assertCount(1, $response->json('files'));
        $this->assertEquals('document.pdf', $response->json('files.0.original_filename'));
    }

    public function test_remove_temp_file()
    {
        // Upload a file first
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $uploadResponse = $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
                'file' => $file,
                'temp_identifier' => $this->tempIdentifier,
            ]);

        $path = $uploadResponse->json('path');

        // Now remove the file
        $response = $this->actingAs($this->user)
            ->delete(route('task-attachments.remove-temp'), [
                'path' => $path,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Check that the file no longer exists
        Storage::assertMissing($path);
    }

    public function test_task_creation_with_attachments()
    {
        // Upload a file first
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $uploadResponse = $this->actingAs($this->user)
            ->post(route('task-attachments.upload'), [
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
        $this->assertNotNull($task);

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
    }

    public function test_task_creation_without_attachments()
    {
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
        $this->assertNotNull($task);

        // Check that no attachments were created
        $this->assertDatabaseMissing('attachments', [
            'attachable_id' => $task->id,
            'attachable_type' => Task::class,
        ]);
    }

    public function test_task_update_with_attachments()
    {
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
            ->post(route('task-attachments.upload'), [
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
        $this->assertNotNull($newAttachment);
        $this->assertEquals('new-document.pdf', $newAttachment->original_filename);
        Storage::assertExists($newAttachment->path);

        // Check that the temp file was deleted
        Storage::assertMissing($tempPath);
    }

    public function test_list_task_attachments()
    {
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
            ->get(route('task-attachments.list', $task));

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

        $this->assertCount(2, $response->json('attachments'));
        $this->assertEquals('document1.pdf', $response->json('attachments.0.original_filename'));
        $this->assertEquals('document2.pdf', $response->json('attachments.1.original_filename'));
    }

    public function test_delete_attachment()
    {
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
            ->delete(route('task-attachments.delete', $attachment));

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Check that the attachment was deleted from the database
        $this->assertDatabaseMissing('attachments', [
            'id' => $attachment->id,
        ]);

        // Check that the file was deleted from storage
        Storage::assertMissing($attachment->path);
    }
}
