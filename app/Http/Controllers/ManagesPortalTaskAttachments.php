<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ManagesPortalTaskAttachments
{
    private function persistTempAttachmentsForTask(Task $task, string $tempIdentifier)
    {
        $tempPath = $this->temporaryAttachments->ownedDirectory($tempIdentifier, auth()->id());
        if ($tempPath === null) {
            return collect();
        }

        $files = $this->temporaryAttachments->ownedFiles($tempIdentifier, auth()->id());
        $created = collect();

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

            $created->push(Attachment::create([
                'attachable_id' => $task->id,
                'attachable_type' => Task::class,
                'original_filename' => $originalFilename,
                'path' => $newPath,
            ]));
        }

        $this->temporaryAttachments->deleteOwnedDirectory($tempIdentifier, auth()->id());

        return $created;
    }

    /**
     * Replace temp attachment URLs in HTML content with final download URLs.
     */
    private function replaceTempUrlsInContent(string $content, string $tempIdentifier, $attachments): string
    {
        if (empty($content) || empty($tempIdentifier) || ! $attachments || $attachments->isEmpty()) {
            return $content;
        }

        $out = $content;
        foreach ($attachments as $attachment) {
            $finalUrl = route('attachments.download', $attachment, false);
            $basename = basename($attachment->path);
            $quotedTemp = preg_quote($tempIdentifier, '#');
            $quotedBase = preg_quote($basename, '#');
            $quotedBaseEnc = preg_quote(rawurlencode($basename), '#');

            $patterns = [
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
}
