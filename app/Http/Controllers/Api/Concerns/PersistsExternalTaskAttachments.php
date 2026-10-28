<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait PersistsExternalTaskAttachments
{
    private function promoteTemporaryAttachments(Request $request, Task $task, array $attributes): void
    {
        if (isset($attributes['temp_identifier'])) {
            $tempIdentifier = $attributes['temp_identifier'];
            $owner = $this->temporaryAttachments->ownerForRequest($request);
            $tempPath = $this->temporaryAttachments->ownedDirectory($tempIdentifier, $owner);

            if ($tempPath !== null) {
                $files = $this->temporaryAttachments->ownedFiles($tempIdentifier, $owner);

                $permanentPath = "attachments/{$task->id}";
                if (! Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                foreach ($files as $file) {
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    $metadataPath = $file.'.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    $storedFilename = basename($file);
                    $newPath = "{$permanentPath}/{$storedFilename}";

                    Storage::move($file, $newPath);

                    Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);

                    if (Storage::exists($metadataPath)) {
                        Storage::delete($metadataPath);
                    }
                }

                $this->temporaryAttachments->deleteOwnedDirectory($tempIdentifier, $owner);
            }
        }

        if (! empty($attributes['temp_identifier'])) {
            $task->load('attachments');
            $task->description = $this->replaceTempUrlsInContent(
                (string) ($task->description ?? ''),
                (string) $attributes['temp_identifier'],
                $task->attachments
            );
            $task->save();
        }
    }

    /**
     * Replace temp attachment URLs in HTML content with final download URLs.
     *
     * External SDK clients embed images via their proxy route:
     * `/shift/api/attachments/temp/{temp}/{filename}`.
     * After we move files to permanent storage, rewrite those URLs to the internal
     * download route (`/attachments/{id}/download`), then rewrite to the client SDK
     * proxy URL at read time (see rewriteContentUrlsToClientProxyUrls()).
     */
    private function replaceTempUrlsInContent(string $content, string $tempIdentifier, $attachments): string
    {
        if (empty($content) || empty($tempIdentifier) || ! $attachments || $attachments->isEmpty()) {
            return $content;
        }

        $out = $content;
        foreach ($attachments as $attachment) {
            $finalUrl = route('attachments.download', $attachment, false);
            $basename = basename((string) $attachment->path);
            $quotedTemp = preg_quote($tempIdentifier, '#');
            $quotedBase = preg_quote($basename, '#');
            $quotedBaseEnc = preg_quote(rawurlencode($basename), '#');

            $patterns = [
                // SDK proxy route (absolute + relative)
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#https?://[^\\s\"'<>]+/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#/shift/api/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                // Portal-style temp route (absolute + relative)
                "#https?://[^\\s\"'<>]+/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#https?://[^\\s\"'<>]+/attachments/temp/{$quotedTemp}/{$quotedBase}#",
                "#/attachments/temp/{$quotedTemp}/{$quotedBaseEnc}#",
                "#/attachments/temp/{$quotedTemp}/{$quotedBase}#",
            ];

            foreach ($patterns as $pattern) {
                $out = preg_replace($pattern, $finalUrl, $out) ?? $out;
            }
        }

        return $out;
    }

    /**
     * Normalize any attachment download URLs found in content to the internal download route.
     *
     * This ensures we never persist client-specific hostnames in task descriptions.
     */
    private function normalizeDownloadUrlsToInternal(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        $patterns = [
            '#https?://[^\"\'<>]+/shift/api/attachments/(\\d+)/download#',
            '#/shift/api/attachments/(\\d+)/download#',
            '#https?://[^\"\'<>]+/attachments/(\\d+)/download#',
            '#/attachments/(\\d+)/download#',
        ];

        $replace = function (string $pattern, string $html) {
            return preg_replace_callback($pattern, function ($m) {
                $id = (int) $m[1];

                return route('attachments.download', ['attachment' => $id], false);
            }, $html) ?? $html;
        };

        $out = $content;
        foreach ($patterns as $pattern) {
            $out = $replace($pattern, $out);
        }

        return $out;
    }

    /**
     * Rewrite any internal attachment download URLs in HTML content to the client SDK proxy URL.
     */
    private function rewriteContentUrlsToClientProxyUrls(string $content, string $clientUrl): string
    {
        if ($content === '' || $clientUrl === '') {
            return $content;
        }

        $clientBase = rtrim($clientUrl, '/');

        $patterns = [
            '#https?://[^\"\'<>]+/attachments/(\\d+)/download#',
            // Only match truly-relative URLs, not the path portion of an absolute URL.
            '#(?<![A-Za-z0-9])/attachments/(\\d+)/download#',
            '#https?://[^\"\'<>]+/shift/api/attachments/(\\d+)/download#',
            // Only match truly-relative URLs, not the path portion of an absolute URL.
            '#(?<![A-Za-z0-9])/shift/api/attachments/(\\d+)/download#',
        ];

        $replace = function (string $pattern, string $html) use ($clientBase) {
            return preg_replace_callback($pattern, function ($m) use ($clientBase) {
                $id = (int) $m[1];

                return $clientBase.'/shift/api/attachments/'.$id.'/download';
            }, $html) ?? $html;
        };

        $out = $content;
        foreach ($patterns as $pattern) {
            $out = $replace($pattern, $out);
        }

        return $out;
    }
}
