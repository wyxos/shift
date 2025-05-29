<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExternalAttachmentController extends Controller
{
    /**
     * Upload a temporary attachment.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'temp_identifier' => 'required|string',
        ]);

        $file = $request->file('file');
        $tempIdentifier = $request->input('temp_identifier');
        $originalFilename = $file->getClientOriginalName();

        // Create temp directory if it doesn't exist
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (!Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        // Generate a unique filename for storage
        $extension = $file->getClientOriginalExtension();
        $storedFilename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) . '_' . uniqid() . '.' . $extension;
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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadMultiple(Request $request)
    {
        $request->validate([
            'attachments' => 'required|array',
            'attachments.*' => 'file|max:10240', // 10MB max
            'temp_identifier' => 'required|string',
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $results = [];

        // Create temp directory if it doesn't exist
        $tempPath = "temp_attachments/{$tempIdentifier}";
        if (!Storage::exists($tempPath)) {
            Storage::makeDirectory($tempPath);
        }

        foreach ($request->file('attachments') as $file) {
            $originalFilename = $file->getClientOriginalName();

            // Generate a unique filename for storage
            $extension = $file->getClientOriginalExtension();
            $storedFilename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) . '_' . uniqid() . '.' . $extension;
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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTemp(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        // Security check to ensure we're only deleting from temp_attachments
        if (!Str::startsWith($path, 'temp_attachments/')) {
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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listTemp(Request $request)
    {
        $request->validate([
            'temp_identifier' => 'required|string',
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (!Storage::exists($tempPath)) {
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
     * Download an attachment.
     *
     * @param Attachment $attachment
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
     */
    public function download(Attachment $attachment)
    {
        // Check if the file exists
        if (!Storage::exists($attachment->path)) {
            return response()->json(['error' => 'File not found'], 404);
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
                $attachment->original_filename,
                ['Content-Type' => 'application/octet-stream']
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
