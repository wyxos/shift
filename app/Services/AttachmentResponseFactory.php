<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentResponseFactory
{
    public function preview(string $path, string $mimeType): StreamedResponse
    {
        return $this->stream($path, $mimeType);
    }

    public function inline(string $path, string $filename, string $mimeType): StreamedResponse
    {
        return $this->stream($path, $mimeType, ResponseHeaderBag::DISPOSITION_INLINE, $filename);
    }

    public function download(string $path, string $filename, string $mimeType): StreamedResponse
    {
        return $this->stream($path, $mimeType, ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    }

    private function stream(
        string $path,
        string $mimeType,
        ?string $disposition = null,
        ?string $filename = null,
    ): StreamedResponse {
        $safeInlineTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];
        if (! in_array(strtolower($mimeType), $safeInlineTypes, true)) {
            $disposition = ResponseHeaderBag::DISPOSITION_ATTACHMENT;
            $filename ??= basename($path);
            if (in_array(strtolower($mimeType), ['image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml'], true)) {
                $mimeType = 'application/octet-stream';
            }
        }

        $headers = [
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => $mimeType,
            'Content-Length' => (string) Storage::size($path),
        ];

        if ($disposition !== null && $filename !== null) {
            $fallbackName = str_replace(['%', '/', '\\'], '-', Str::ascii($filename));
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(
                $disposition,
                $filename,
                $fallbackName !== '' ? $fallbackName : 'download',
            );
        }

        return response()->stream(function () use ($path): void {
            $stream = Storage::readStream($path);

            abort_if($stream === false, 404, 'File not found');

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 8192);

                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
