<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExternalAttachmentController extends Controller
{
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
