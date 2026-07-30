<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Services\ExternalUserService;
use App\Services\TemporaryAttachmentStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shift\Core\ChunkedUploadConfig;

class ExternalAttachmentController extends Controller
{
    public function __construct(
        private readonly ExternalUserService $externalUserService,
        private readonly TemporaryAttachmentStorage $temporaryAttachments,
    ) {}

    private function resolveProjectFromRequest(): ?Project
    {
        return Project::query()
            ->visibleTo(request()->user()?->id)
            ->where('token', request('project'))
            ->first();
    }

    private function currentExternalUser(Project $project): ?ExternalUser
    {
        return $this->externalUserService->find(
            $project,
            request()->offsetGet('user.id'),
            request()->offsetGet('user.environment'),
            request()->offsetGet('user.url'),
        );
    }

    private function externalUserHasAccess(Task $task, ExternalUser $externalUser): bool
    {
        return $this->externalUserService->canViewProjectItem($task, $externalUser);
    }

    private function isExternalUserRequest(): bool
    {
        return request()->has('project') &&
            request()->offsetGet('user.id') !== null &&
            request()->offsetGet('user.environment') !== null &&
            request()->offsetGet('user.url') !== null;
    }

    /**
     * Upload a temporary attachment.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:'.ChunkedUploadConfig::MAX_UPLOAD_KB, // 40MB max
            'temp_identifier' => ['required', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
        ]);

        $file = $request->file('file');
        $tempIdentifier = $request->input('temp_identifier');
        $originalFilename = $file->getClientOriginalName();

        $tempPath = $this->temporaryAttachments->claim($tempIdentifier, $request->user()?->id);
        abort_if($tempPath === null, 404);

        // Generate a unique filename for storage
        $extension = $file->getClientOriginalExtension();
        $baseName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) ?: 'upload';
        $extensionSuffix = $extension !== '' && strtolower($extension) !== 'meta' ? '.'.$extension : '';
        $storedFilename = $baseName.'_'.uniqid().$extensionSuffix;
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
            'temp_identifier' => ['required', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $results = [];

        $tempPath = $this->temporaryAttachments->claim($tempIdentifier, $request->user()?->id);
        abort_if($tempPath === null, 404);

        foreach ($request->file('attachments') as $file) {
            $originalFilename = $file->getClientOriginalName();

            // Generate a unique filename for storage
            $extension = $file->getClientOriginalExtension();
            $baseName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) ?: 'upload';
            $extensionSuffix = $extension !== '' && strtolower($extension) !== 'meta' ? '.'.$extension : '';
            $storedFilename = $baseName.'_'.uniqid().$extensionSuffix;
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

        $clientPath = $request->string('path')->toString();
        if ($this->temporaryAttachments->canonicalFilePath($clientPath) === null) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        $path = $this->temporaryAttachments->ownedFilePath($clientPath, $request->user()?->id);
        if ($path !== null && Storage::exists($path)) {
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
            'temp_identifier' => ['required', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $files = $this->temporaryAttachments->ownedFiles($tempIdentifier, $request->user()?->id);
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
            'temp_identifier' => ['required', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'mime_type' => 'nullable|string',
        ]);

        abort_if($this->temporaryAttachments->claim($data['temp_identifier'], $request->user()?->id) === null, 404);

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
            'user_id' => $request->user()?->id,
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

        $meta = $this->readChunkMeta($uploadId);
        if (! $meta) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

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
        $tempPath = $this->temporaryAttachments->ownedDirectory($tempIdentifier, $request->user()?->id);
        if ($tempPath === null) {
            return response()->json(['error' => 'Missing temp identifier'], 422);
        }

        $originalFilename = (string) ($meta['original_filename'] ?? 'upload.bin');
        $baseName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) ?: 'upload';
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $extensionSuffix = $extension !== '' && strtolower($extension) !== 'meta' ? '.'.$extension : '';
        $storedFilename = $baseName.'_'.uniqid().$extensionSuffix;
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
    public function showTemp(Request $request, string $temp, string $filename)
    {
        $path = $this->temporaryAttachments->ownedRouteFilePath($temp, $filename, $request->user()?->id);
        if ($path === null || ! Storage::exists($path)) {
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
    public function download(Request $request, Attachment $attachment)
    {
        // Check if the file exists
        if (! Storage::exists($attachment->path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $task = $this->getTaskFromAttachment($attachment);

        if (! $task) {
            return response()->json(['error' => 'Attachment not associated with a task'], 404);
        }

        // Check if this is an external user request (has project parameter and user context)
        $isExternalUserRequest = $this->isExternalUserRequest();

        if ($isExternalUserRequest) {
            // External user access control
            $project = $this->resolveProjectFromRequest();
            if ($project === null || $task->project_id !== $project->id) {
                return response()->json(['error' => 'Task not found in the specified project'], 404);
            }

            $externalUser = $this->currentExternalUser($project);

            if (! $externalUser) {
                return response()->json(['error' => 'External user not found'], 404);
            }

            if (! $this->externalUserHasAccess($task, $externalUser)) {
                return response()->json(['error' => 'Unauthorized to access this attachment'], 403);
            }
        } elseif (! Task::query()->visibleTo($request->user()?->id)->whereKey($task->id)->exists()) {
            return response()->json(['error' => 'Attachment not found'], 404);
        }

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

        $metadata = json_decode(Storage::get($metaPath), true) ?: null;
        if (! is_array($metadata)
            || ! isset($metadata['user_id'])
            || ! hash_equals((string) request()->user()?->id, (string) $metadata['user_id'])) {
            return null;
        }

        return $metadata;
    }
}
