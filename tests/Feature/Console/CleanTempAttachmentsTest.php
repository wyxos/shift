<?php

namespace Tests\Feature\Console;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanTempAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_command_deletes_old_temp_files()
    {
        // Create a temp directory with a file that's older than 24 hours
        $oldTempId = 'old-temp-' . time();
        $oldTempPath = "temp_attachments/{$oldTempId}";
        Storage::makeDirectory($oldTempPath);

        // Create a file in the old temp directory
        $file = UploadedFile::fake()->create('old-document.pdf', 100);
        $oldFilePath = "{$oldTempPath}/old-document.pdf";
        Storage::put($oldFilePath, file_get_contents($file));

        // Modify the last modified time to be older than 24 hours
        $yesterday = Carbon::now()->subHours(25);
        touch(Storage::path($oldFilePath), $yesterday->timestamp);
        touch(Storage::path($oldTempPath), $yesterday->timestamp);

        // Create a temp directory with a file that's newer than 24 hours
        $newTempId = 'new-temp-' . time();
        $newTempPath = "temp_attachments/{$newTempId}";
        Storage::makeDirectory($newTempPath);

        // Create a file in the new temp directory
        $file = UploadedFile::fake()->create('new-document.pdf', 100);
        $newFilePath = "{$newTempPath}/new-document.pdf";
        Storage::put($newFilePath, file_get_contents($file));

        // Run the command
        $this->artisan('attachments:clean-temp')
            ->expectsOutput('Cleaning temporary attachments...')
            ->expectsOutput("Deleted: {$oldTempPath}")
            ->expectsOutput('Cleaned 1 temporary attachment directories.')
            ->assertSuccessful();

        // Check that the old directory was deleted
        Storage::assertMissing($oldTempPath);
        Storage::assertMissing($oldFilePath);

        // Check that the new directory still exists
        Storage::assertExists($newTempPath);
        Storage::assertExists($newFilePath);
    }

    public function test_command_does_not_delete_new_temp_files()
    {
        // Create a temp directory with a file that's newer than 24 hours
        $newTempId = 'new-temp-' . time();
        $newTempPath = "temp_attachments/{$newTempId}";
        Storage::makeDirectory($newTempPath);

        // Create a file in the new temp directory
        $file = UploadedFile::fake()->create('new-document.pdf', 100);
        $newFilePath = "{$newTempPath}/new-document.pdf";
        Storage::put($newFilePath, file_get_contents($file));

        // Run the command
        $this->artisan('attachments:clean-temp')
            ->expectsOutput('Cleaning temporary attachments...')
            ->expectsOutput('Cleaned 0 temporary attachment directories.')
            ->assertSuccessful();

        // Check that the new directory still exists
        Storage::assertExists($newTempPath);
        Storage::assertExists($newFilePath);
    }
}
