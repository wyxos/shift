<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            'file' => 'required|file|max:10240', // 10MB max
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
            'url' => Storage::url($path),
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
                'url' => Storage::url($file),
            ];
        }

        return response()->json(['files' => $fileData]);
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
