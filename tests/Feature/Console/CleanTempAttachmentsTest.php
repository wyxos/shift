<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('command deletes stale temporary attachment and chunk directories with their metadata', function () {
    $attachmentDirectory = 'temp_attachments/stale-attachment';
    $attachmentPath = "{$attachmentDirectory}/document.pdf";
    $attachmentMetadataPath = "{$attachmentPath}.meta";
    $chunkDirectory = 'temp_chunks/stale-upload';
    $chunkPath = "{$chunkDirectory}/chunk_0.part";
    $chunkMetadataPath = "{$chunkDirectory}/meta.json";

    Storage::put($attachmentPath, 'attachment');
    Storage::put($attachmentMetadataPath, json_encode(['original_filename' => 'document.pdf']));
    Storage::put($chunkPath, 'chunk');
    Storage::put($chunkMetadataPath, json_encode(['created_at' => now()->subHours(25)->toIso8601String()]));

    $staleTimestamp = now()->subHours(25)->getTimestamp();

    foreach ([
        $attachmentPath,
        $attachmentMetadataPath,
        $attachmentDirectory,
        $chunkPath,
        $chunkMetadataPath,
        $chunkDirectory,
    ] as $path) {
        touch(Storage::path($path), $staleTimestamp);
    }

    $this->artisan('attachments:clean-temp')
        ->expectsOutput('Cleaning temporary attachments...')
        ->expectsOutput("Deleted: {$attachmentDirectory}")
        ->expectsOutput('Cleaned 1 temporary attachment directories.')
        ->expectsOutput("Deleted: {$chunkDirectory}")
        ->expectsOutput('Cleaned 1 temporary chunk directories.')
        ->assertSuccessful();

    Storage::assertMissing($attachmentDirectory);
    Storage::assertMissing($attachmentPath);
    Storage::assertMissing($attachmentMetadataPath);
    Storage::assertMissing($chunkDirectory);
    Storage::assertMissing($chunkPath);
    Storage::assertMissing($chunkMetadataPath);
});

test('command keeps temporary directories with recent file or metadata activity', function () {
    $attachmentDirectory = 'temp_attachments/fresh-attachment';
    $attachmentPath = "{$attachmentDirectory}/document.pdf";
    $attachmentMetadataPath = "{$attachmentPath}.meta";
    $chunkDirectory = 'temp_chunks/fresh-upload';
    $chunkPath = "{$chunkDirectory}/chunk_0.part";
    $chunkMetadataPath = "{$chunkDirectory}/meta.json";

    Storage::put($attachmentPath, 'attachment');
    Storage::put($attachmentMetadataPath, json_encode(['original_filename' => 'document.pdf']));
    Storage::put($chunkPath, 'chunk');
    Storage::put($chunkMetadataPath, json_encode(['created_at' => now()->toIso8601String()]));

    $staleTimestamp = now()->subHours(25)->getTimestamp();
    touch(Storage::path($attachmentDirectory), $staleTimestamp);
    touch(Storage::path($chunkDirectory), $staleTimestamp);

    $this->artisan('attachments:clean-temp')
        ->expectsOutput('Cleaning temporary attachments...')
        ->expectsOutput('Cleaned 0 temporary attachment directories.')
        ->expectsOutput('Cleaned 0 temporary chunk directories.')
        ->assertSuccessful();

    Storage::assertExists($attachmentDirectory);
    Storage::assertExists($attachmentPath);
    Storage::assertExists($attachmentMetadataPath);
    Storage::assertExists($chunkDirectory);
    Storage::assertExists($chunkPath);
    Storage::assertExists($chunkMetadataPath);
});

test('command never deletes permanent attachments', function () {
    $permanentDirectory = 'attachments/task-1';
    $permanentPath = "{$permanentDirectory}/document.pdf";

    Storage::put($permanentPath, 'permanent attachment');

    $staleTimestamp = now()->subDays(30)->getTimestamp();
    touch(Storage::path($permanentPath), $staleTimestamp);
    touch(Storage::path($permanentDirectory), $staleTimestamp);

    $this->artisan('attachments:clean-temp')->assertSuccessful();

    Storage::assertExists($permanentDirectory);
    Storage::assertExists($permanentPath);
});
