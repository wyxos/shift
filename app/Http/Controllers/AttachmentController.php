<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wyxos\ShiftShared\ChunkedUploadConfig;

class AttachmentController extends Controller
{
    /**
     * Upload a file to temporary storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $validator = validator($request->all(), [
            'file' => 'required|file|max:'.ChunkedUploadConfig::MAX_UPLOAD_KB, // 40MB max
            'temp_identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $file = $request->file('file');
        $tempIdentifier = $request->input('temp_identifier');
        $originalFilename = $file->getClientOriginalName();

        // Create temp directory if it doesn't exist
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (!Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        // Store the file with original filename
        $filename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $storedFilename = $filename . '_' . uniqid() . '.' . $extension;
        $path = $file->storeAs($tempPath, $storedFilename);

        // Store metadata about the file
        $metadataPath = $tempPath . '/' . $storedFilename . '.meta';
        Storage::put($metadataPath, json_encode([
            'original_filename' => $originalFilename
        ]));

        return response()->json([
            'original_filename' => $originalFilename,
            'path' => $path,
            'url' => route('attachments.temp', [
                'temp' => $tempIdentifier,
                'filename' => $storedFilename,
            ]),
        ]);
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
        $storedFilename = $baseName.'_'.uniqid().($extension ? '.'.$extension : '');
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

        $metadataPath = $tempPath.'/'.$storedFilename.'.meta';
        Storage::put($metadataPath, json_encode([
            'original_filename' => $originalFilename,
        ]));

        Storage::deleteDirectory($dir);

        return response()->json([
            'original_filename' => $originalFilename,
            'path' => $finalPath,
            'url' => route('attachments.temp', [
                'temp' => $tempIdentifier,
                'filename' => $storedFilename,
            ]),
        ]);
    }

    /**
     * List all files in a temporary directory.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTempFiles(Request $request)
    {
        $validator = validator($request->all(), [
            'temp_identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tempIdentifier = $request->input('temp_identifier');
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (!Storage::exists($tempPath)) {
            return response()->json(['files' => []]);
        }

        $files = Storage::files($tempPath);
        $fileData = [];

        foreach ($files as $file) {
            // Skip metadata files
            if (Str::endsWith($file, '.meta')) {
                continue;
            }

            // Try to get original filename from metadata
            $metadataPath = $file . '.meta';
            $originalFilename = basename($file);

            if (Storage::exists($metadataPath)) {
                $metadata = json_decode(Storage::get($metadataPath), true);
                if (isset($metadata['original_filename'])) {
                    $originalFilename = $metadata['original_filename'];
                }
            }

            $fileData[] = [
                'path' => $file,
                'original_filename' => $originalFilename,
                'url' => route('attachments.temp', [
                    'temp' => $tempIdentifier,
                    'filename' => basename($file),
                ]),
            ];
        }

        return response()->json(['files' => $fileData]);
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

        $path = "temp_attachments/{$safeTemp}/{$filename}";
        if (!Storage::exists($path)) {
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
     * Remove a file from temporary storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTempFile(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        if (Storage::exists($path)) {
            Storage::delete($path);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'File not found'], 404);
    }

    /**
     * List all attachments for a model.
     *
     * @param string $type
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function listAttachments($type, $id)
    {
        // Map the type to a model class
        $modelClass = $this->getModelClass($type);

        if (!$modelClass) {
            return response()->json(['error' => 'Invalid type'], 400);
        }

        $model = $modelClass::findOrFail($id);

        $attachments = $model->attachments()->get()->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        });

        return response()->json(['attachments' => $attachments]);
    }

    /**
     * List all attachments for a task.
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTaskAttachments(Task $task)
    {
        $attachments = $task->attachments()->get()->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        });

        return response()->json(['attachments' => $attachments]);
    }

    /**
     * Delete an attachment.
     *
     * @param Attachment $attachment
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAttachment(Attachment $attachment)
    {
        // Check if the file exists
        if (Storage::exists($attachment->path)) {
            // Delete the file
            Storage::delete($attachment->path);
        }

        // Delete the attachment record
        $attachment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get the model class for a given type.
     *
     * @param string $type
     * @return string|null
     */
    private function getModelClass($type)
    {
        $map = [
            'task' => Task::class,
            'task_thread' => \App\Models\TaskThread::class,
            // Add more mappings as needed
        ];

        return $map[$type] ?? null;
    }

    /**
     * Download an attachment.
     *
     * @param Attachment $attachment
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     */
    public function downloadAttachment(Attachment $attachment)
    {
        // Check if the file exists
        if (!Storage::exists($attachment->path)) {
            abort(404, 'File not found');
        }

        // Check if the file is an image
        $extension = pathinfo($attachment->original_filename, PATHINFO_EXTENSION);
        $isImage = in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp']);

        if ($isImage) {
            // For images, return an inline response
            return response()->file(
                Storage::path($attachment->path),
                ['Content-Type' => $this->getMimeType($extension)]
            );
        } else {
            // For non-images, return a download response
            return response()->download(
                Storage::path($attachment->path),
                $attachment->original_filename
            );
        }
    }

    /**
     * Get the MIME type for a file extension.
     *
     * @param string $extension
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
}
