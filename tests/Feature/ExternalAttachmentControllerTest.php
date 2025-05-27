<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExternalAttachmentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Task $task;
    protected Attachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fake disk for testing
        Storage::fake('local');

        // Create a user
        $this->user = User::factory()->create();

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
    }

    public function test_download_returns_file_for_valid_attachment()
    {
        $response = $this->actingAs($this->user)
            ->get(route('api.attachments.download', $this->attachment));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/octet-stream');
    }

    public function test_download_returns_404_for_missing_file()
    {
        // Delete the file but keep the attachment record
        Storage::delete($this->attachment->path);

        $response = $this->actingAs($this->user)
            ->get(route('api.attachments.download', $this->attachment));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'File not found']);
    }

    public function test_download_returns_image_inline_for_image_files()
    {
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
    }
}
