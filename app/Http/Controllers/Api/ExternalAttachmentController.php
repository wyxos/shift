<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shift\Core\ChunkedUploadConfig;

class ExternalAttachmentController extends Controller
{
    /**
     * Upload a temporary attachment.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:'.ChunkedUploadConfig::MAX_UPLOAD_KB, // 40MB max
            'temp_identifier' => 'required|string',
        ]);

        $file = $request->file('file');
        $tempIdentifier = $request->input('temp_identifier');
        $originalFilename = $file->getClientOriginalName();

        // Create temp directory if it doesn't exist
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (! Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        // Generate a unique filename for storage
        $extension = $file->getClientOriginalExtension();
        $storedFilename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)).'_'.uniqid().'.'.$extension;
        $filePath = "{$tempPath}/{$storedFilename}";

        // Store the file
        $file->storeAs($tempPath, $storedFilename);

        // Store metadata
        $metadata = [
            'original_filename' => $originalFilename,
            'uploaded_at' => now()->toIso8601String(),
        ];
        Storage::put("{$filePath}.meta", json_encode($metadata));

        return response()->json([
            'original_filename' => $originalFilename,
            'path' => $filePath,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }

    /**
     * Upload multiple attachments at once.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadMultiple(Request $request)
    {
        $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:'.ChunkedUploadConfig::MAX_UPLOAD_KB, // 40MB max
            'temp_identifier' => 'required|string',
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $results = [];

        // Create temp directory if it doesn't exist
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (! Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        foreach ($request->file('attachments') as $file) {
            $originalFilename = $file->getClientOriginalName();

            // Generate a unique filename for storage
            $extension = $file->getClientOriginalExtension();
            $storedFilename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)).'_'.uniqid().'.'.$extension;
            $filePath = "{$tempPath}/{$storedFilename}";

            // Store the file
            $file->storeAs($tempPath, $storedFilename);

            // Store metadata
            $metadata = [
                'original_filename' => $originalFilename,
                'uploaded_at' => now()->toIso8601String(),
            ];
            Storage::put("{$filePath}.meta", json_encode($metadata));

            $results[] = [
                'original_filename' => $originalFilename,
                'path' => $filePath,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return response()->json(['files' => $results]);
    }

    /**
     * Remove a temporary attachment.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTemp(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Security check to ensure we're only deleting from temp_attachments
        if (! Str::startsWith($path, 'temp_attachments/')) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        if (Storage::exists($path)) {
            Storage::delete($path);

            // Delete metadata file if it exists
            $metaPath = "{$path}.meta";
            if (Storage::exists($metaPath)) {
                Storage::delete($metaPath);
            }

            return response()->json(['message' => 'File removed successfully']);
        }

        return response()->json(['error' => 'File not found'], 404);
    }

    /**
     * List temporary attachments.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTemp(Request $request)
    {
        $request->validate([
            'temp_identifier' => 'required|string',
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (! Storage::exists($tempPath)) {
            return response()->json(['files' => []]);
        }

        $files = Storage::files($tempPath);
        $result = [];

        foreach ($files as $file) {
            // Skip metadata files
            if (Str::endsWith($file, '.meta')) {
                continue;
            }

            $originalFilename = basename($file);
            $metaPath = "{$file}.meta";

            if (Storage::exists($metaPath)) {
                $metadata = json_decode(Storage::get($metaPath), true);
                if (isset($metadata['original_filename'])) {
                    $originalFilename = $metadata['original_filename'];
                }
            }

            $result[] = [
                'original_filename' => $originalFilename,
                'path' => $file,
                'size' => Storage::size($file),
                'mime_type' => Storage::mimeType($file),
            ];
        }

        return response()->json(['files' => $result]);
    }

    /**
     * Initialize a chunked upload session.
     */
    public function uploadInit(Request $request)
    {
        $data = $request->validate([
            'filename' => 'required|string',
            'size' => 'required|integer|min:1|max:'.ChunkedUploadConfig::MAX_UPLOAD_BYTES,
            'temp_identifier' => 'required|string',
            'mime_type' => 'nullable|string',
        ]);

        $uploadId = (string) Str::uuid();
        $dir = "temp_chunks/{$uploadId}";
        if (! Storage::exists($dir)) {
            Storage::makeDirectory($dir);
        }

        $totalChunks = (int) ceil($data['size'] / ChunkedUploadConfig::CHUNK_SIZE_BYTES);
        $meta = [
            'original_filename' => $data['filename'],
            'size' => (int) $data['size'],
            'temp_identifier' => $data['temp_identifier'],
            'mime_type' => $data['mime_type'] ?? null,
            'chunk_size' => ChunkedUploadConfig::CHUNK_SIZE_BYTES,
            'total_chunks' => $totalChunks,
            'created_at' => now()->toIso8601String(),
        ];

        Storage::put("{$dir}/meta.json", json_encode($meta));

        return response()->json([
            'upload_id' => $uploadId,
            'chunk_size' => ChunkedUploadConfig::CHUNK_SIZE_BYTES,
            'total_chunks' => $totalChunks,
            'max_bytes' => ChunkedUploadConfig::MAX_UPLOAD_BYTES,
        ]);
    }

    /**
     * Return chunk upload status for resumable uploads.
     */
    public function uploadStatus(Request $request)
    {
        $data = $request->validate([
            'upload_id' => 'required|string',
        ]);

        $uploadId = $this->sanitizeUploadId($data['upload_id']);
        if (! $uploadId) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $metaPath = $this->chunkMetaPath($uploadId);
        if (! Storage::exists($metaPath)) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $meta = json_decode(Storage::get($metaPath), true) ?: [];
        $dir = $this->chunkDir($uploadId);
        $files = Storage::files($dir);
        $uploaded = [];

        foreach ($files as $file) {
            if (preg_match('/chunk_(\d+)\.part$/', $file, $m)) {
                $uploaded[] = (int) $m[1];
            }
        }

        sort($uploaded);

        return response()->json([
            'upload_id' => $uploadId,
            'uploaded_chunks' => $uploaded,
            'total_chunks' => (int) ($meta['total_chunks'] ?? 0),
            'chunk_size' => (int) ($meta['chunk_size'] ?? ChunkedUploadConfig::CHUNK_SIZE_BYTES),
        ]);
    }

    /**
     * Upload a single chunk for an existing chunked upload session.
     */
    public function uploadChunk(Request $request)
    {
        $data = $request->validate([
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:'.ChunkedUploadConfig::CHUNK_SIZE_KB,
        ]);

        $uploadId = $this->sanitizeUploadId($data['upload_id']);
        if (! $uploadId) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $meta = $this->readChunkMeta($uploadId);
        if (! $meta) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $totalChunks = (int) ($meta['total_chunks'] ?? 0);
        $chunkIndex = (int) $data['chunk_index'];
        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            return response()->json(['error' => 'Invalid chunk index'], 422);
        }

        $dir = $this->chunkDir($uploadId);
        if (! Storage::exists($dir)) {
            Storage::makeDirectory($dir);
        }

        $file = $request->file('chunk');
        Storage::putFileAs($dir, $file, "chunk_{$chunkIndex}.part");

        return response()->json(['ok' => true]);
    }

    /**
     * Complete a chunked upload and assemble the final file.
     */
    public function uploadComplete(Request $request)
    {
        $data = $request->validate([
            'upload_id' => 'required|string',
        ]);

        $uploadId = $this->sanitizeUploadId($data['upload_id']);
        if (! $uploadId) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $meta = $this->readChunkMeta($uploadId);
        if (! $meta) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $dir = $this->chunkDir($uploadId);
        $totalChunks = (int) ($meta['total_chunks'] ?? 0);
        $missing = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            if (! Storage::exists("{$dir}/chunk_{$i}.part")) {
                $missing[] = $i;
            }
        }

        if (! empty($missing)) {
            return response()->json(['error' => 'Missing chunks', 'missing' => $missing], 409);
        }

        $tempIdentifier = (string) ($meta['temp_identifier'] ?? '');
        if ($tempIdentifier === '') {
            return response()->json(['error' => 'Missing temp identifier'], 422);
        }

        $originalFilename = (string) ($meta['original_filename'] ?? 'upload.bin');
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (! Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $storedFilename = Str::slug($baseName).'_'.uniqid().($extension ? '.'.$extension : '');
        $finalPath = "{$tempPath}/{$storedFilename}";

        $finalAbs = Storage::path($finalPath);
        $out = fopen($finalAbs, 'wb');
        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkAbs = Storage::path("{$dir}/chunk_{$i}.part");
            $in = fopen($chunkAbs, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $expectedSize = (int) ($meta['size'] ?? 0);
        if ($expectedSize > 0 && filesize($finalAbs) !== $expectedSize) {
            Storage::delete($finalPath);
            return response()->json(['error' => 'File size mismatch'], 422);
        }

        Storage::put("{$finalPath}.meta", json_encode([
            'original_filename' => $originalFilename,
            'uploaded_at' => now()->toIso8601String(),
        ]));

        Storage::deleteDirectory($dir);

        return response()->json([
            'original_filename' => $originalFilename,
            'path' => $finalPath,
            'size' => Storage::size($finalPath),
            'mime_type' => Storage::mimeType($finalPath),
        ]);
    }

    /**
     * Serve a temporary attachment file inline.
     */
    public function showTemp(string $temp, string $filename)
    {
        // basic sanitization on temp segment
        $safeTemp = preg_replace('/[^a-zA-Z0-9_\-]/', '', $temp);
        if ($safeTemp !== $temp) {
            abort(404);
        }

        if (Str::contains($filename, '..')) {
            abort(404);
        }

        $path = "temp_attachments/{$safeTemp}/{$filename}";
        if (! Storage::exists($path)) {
            abort(404, 'File not found');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mime = $this->getMimeType($extension);

        return response()->file(
            Storage::path($path),
            ['Content-Type' => $mime]
        );
    }

    /**
     * Download an attachment.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     */
    public function download(Attachment $attachment)
    {
        // Check if the file exists
        if (! Storage::exists($attachment->path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Check if this is an external user request (has project parameter and user context)
        $isExternalUserRequest = request()->has('project') &&
                                 request()->offsetGet('user.id') !== null &&
                                 request()->offsetGet('user.environment') !== null &&
                                 request()->offsetGet('user.url') !== null;

        if ($isExternalUserRequest) {
            // External user access control
            // Get the task that this attachment belongs to (either directly or through a thread)
            $task = $this->getTaskFromAttachment($attachment);

            if (! $task) {
                return response()->json(['error' => 'Attachment not associated with a task'], 404);
            }

            // Verify the task belongs to the project specified in the request
            if ($task->project->token !== request('project')) {
                return response()->json(['error' => 'Task not found in the specified project'], 404);
            }

            // Get the current external user
            $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
                ->where('environment', request()->offsetGet('user.environment'))
                ->where('url', request()->offsetGet('user.url'))
                ->first();

            if (! $externalUser) {
                return response()->json(['error' => 'External user not found'], 404);
            }

            // Check if the external user is the submitter or has been granted access
            $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
            $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

            if (! $isSubmitter && ! $hasAccess) {
                return response()->json(['error' => 'Unauthorized to access this attachment'], 403);
            }
        }
        // For regular authenticated users, no additional access control is needed
        // They can access attachments through normal Laravel authentication

        return Storage::response($attachment->path, $attachment->original_filename);
    }

    /**
     * Get the task associated with an attachment.
     * Attachments can belong to either a Task directly or a TaskThread (which belongs to a Task).
     */
    private function getTaskFromAttachment(Attachment $attachment): ?Task
    {
        $attachable = $attachment->attachable;

        if (! $attachable) {
            return null;
        }

        // If the attachment belongs directly to a Task
        if ($attachable instanceof Task) {
            return $attachable;
        }

        // If the attachment belongs to a TaskThread, get the Task through the thread
        if ($attachable instanceof TaskThread) {
            return $attachable->task;
        }

        return null;
    }

    /**
     * Get the MIME type for a file extension.
     *
     * @param  string  $extension
     * @return string
     */
    private function getMimeType($extension)
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    private function sanitizeUploadId(string $uploadId): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $uploadId)) {
            return null;
        }

        return $uploadId;
    }

    private function chunkDir(string $uploadId): string
    {
        return "temp_chunks/{$uploadId}";
    }

    private function chunkMetaPath(string $uploadId): string
    {
        return $this->chunkDir($uploadId).'/meta.json';
    }

    private function readChunkMeta(string $uploadId): ?array
    {
        $metaPath = $this->chunkMetaPath($uploadId);
        if (! Storage::exists($metaPath)) {
            return null;
        }

        return json_decode(Storage::get($metaPath), true) ?: null;
    }
}
