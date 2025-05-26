<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskAttachmentController extends Controller
{
    /**
     * Upload a file to temporary storage.
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

        // Store the file
        $path = $file->store($tempPath);

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
        $request->validate([
            'temp_identifier' => 'required|string',
        ]);

        $tempIdentifier = $request->input('temp_identifier');
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (!Storage::exists($tempPath)) {
            return response()->json(['files' => []]);
        }

        $files = Storage::files($tempPath);
        $fileData = [];

        foreach ($files as $file) {
            $fileData[] = [
                'path' => $file,
                'original_filename' => basename($file),
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
}
