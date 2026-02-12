<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskThreadController extends Controller
{
    /**
     * Get all threads for a task.
     */
    public function index(Task $task): JsonResponse
    {
        $internalThreads = $this->getThreadsByType($task, 'internal');
        $externalThreads = $this->getThreadsByType($task, 'external');

        return response()->json([
            'internal' => $internalThreads,
            'external' => $externalThreads,
        ]);
    }

    /**
     * Get threads by type for a task.
     */
    private function getThreadsByType(Task $task, string $type): array
    {
        return $task->threads()
            ->ofType($type)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($thread) {
                // Filter out attachments that are already embedded in the content
                $content = (string) ($thread->content ?? '');
                $attachments = $thread->attachments()->get()
                    ->filter(function ($attachment) use ($content) {
                        $downloadUrlRel = route('attachments.download', $attachment, false);
                        $downloadUrlAbs = url($downloadUrlRel);

                        return Str::doesntContain($content, $downloadUrlRel);
                    })
                    ->map(function ($attachment) {
                        return [
                            'id' => $attachment->id,
                            'original_filename' => $attachment->original_filename,
                            'path' => $attachment->path,
                            'url' => route('attachments.download', $attachment),
                            'created_at' => $attachment->created_at,
                        ];
                    })
                    ->values();

                return [
                    'id' => $thread->id,
                    'content' => $thread->content,
                    'sender_name' => $thread->sender_name,
                    'is_current_user' => $thread->sender_id === Auth::id() && $thread->sender_type === get_class(Auth::user()),
                    'created_at' => $thread->created_at,
                    'attachments' => $attachments,
                ];
            })
            ->toArray();
    }

    /**
     * Store a new thread message.
     *
     * @throws ConnectionException
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $request->validate([
            'content' => 'required|string',
            'type' => 'required|in:internal,external',
            'temp_identifier' => 'nullable|string',
        ]);

        $user = Auth::user();

        $thread = new TaskThread([
            'task_id' => $task->id,
            'type' => $request->input('type'),
            'content' => $request->input('content'),
            'sender_name' => $user->name,
        ]);

        $thread->sender()->associate($user);
        $thread->save();

        // Process any temporary attachments
        if ($request->has('temp_identifier')) {
            $this->processTemporaryAttachments($request->temp_identifier, $thread);
        }

        // After moving attachments, replace temp URLs in content with final URLs
        if ($request->filled('temp_identifier')) {
            $thread->load('attachments');
            $thread->content = $this->replaceTempUrlsInContent(
                $thread->content,
                $request->input('temp_identifier'),
                $thread->attachments
            );
            $thread->save();
        }

        // Get the thread with attachments
        $thread->load('attachments');

        if ($request->input('type') === 'external') {
            $externalUsers = collect();

            // Check if the submitter is an external user
            if ($task->isExternallySubmitted()) {
                /** @var ExternalUser $externalUser */
                $externalUser = $task->submitter;
                $externalUsers->push($externalUser);
            }

            // Get all external users who have access to the task
            // If submitter is already in the collection, it won't be added again
            $task->externalUsers->each(function ($user) use ($externalUsers) {
                if (! $externalUsers->contains('id', $user->id)) {
                    $externalUsers->push($user);
                }
            });

            // Queue delayed notifications to all external users
            foreach ($externalUsers as $externalUser) {
                $payload = [
                    'type' => 'task_thread',
                    'user_id' => $externalUser->external_id,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                ];

                $externalUserData = [
                    'url' => $externalUser->url,
                    'email' => $externalUser->email,
                    'external_id' => $externalUser->external_id,
                ];

                // Dispatch job with 1-minute delay that will check if thread still exists
                \App\Jobs\SendTaskThreadNotification::dispatch(
                    $thread->id,
                    $externalUserData,
                    $payload
                );
            }
        }

        // Filter out attachments already embedded in the content for response
        $content = (string) ($thread->content ?? '');
        $responseAttachments = $thread->attachments->filter(function ($attachment) use ($content) {
            $downloadUrlRel = route('attachments.download', $attachment, false);
            $downloadUrlAbs = url($downloadUrlRel);

            return strpos($content, $downloadUrlRel) === false && strpos($content, $downloadUrlAbs) === false;
        })->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        })->values();

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'content' => $thread->content,
                'sender_name' => $thread->sender_name,
                'is_current_user' => true,
                'created_at' => $thread->created_at,
                'attachments' => $responseAttachments,
            ],
        ], 201);
    }

    /**
     * Process temporary attachments and associate them with the thread.
     */
    private function processTemporaryAttachments(?string $tempIdentifier, TaskThread $thread): void
    {
        if ($tempIdentifier === null) {
            return;
        }

        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (! Storage::exists($tempPath)) {
            return;
        }

        $files = Storage::files($tempPath);

        foreach ($files as $file) {
            // Skip metadata files
            if (str_ends_with($file, '.meta')) {
                continue;
            }

            // Get original filename from metadata
            $metadataPath = $file.'.meta';
            $originalFilename = basename($file);

            if (Storage::exists($metadataPath)) {
                $metadata = json_decode(Storage::get($metadataPath), true);
                if (isset($metadata['original_filename'])) {
                    $originalFilename = $metadata['original_filename'];
                }
            }

            // Move file to permanent storage
            $newPath = "attachments/task_threads/{$thread->id}/".basename($file);
            Storage::move($file, $newPath);

            // Create attachment record
            $attachment = new Attachment([
                'original_filename' => $originalFilename,
                'path' => $newPath,
            ]);

            $thread->attachments()->save($attachment);

            // Delete metadata file
            if (Storage::exists($metadataPath)) {
                Storage::delete($metadataPath);
            }
        }

        // Clean up temp directory
        Storage::deleteDirectory($tempPath);
    }

    /**
     * Get a specific thread message.
     */
    public function show(Task $task, TaskThread $thread): JsonResponse
    {
        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        $attachments = $thread->attachments()->get()->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        })->values();

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'content' => $thread->content,
                'sender_name' => $thread->sender_name,
                'is_current_user' => $thread->sender_id === Auth::id() && $thread->sender_type === get_class(Auth::user()),
                'created_at' => $thread->created_at,
                'attachments' => $attachments,
            ],
        ]);
    }

    /**
     * Delete a thread message.
     */
    public function destroy(Task $task, TaskThread $thread): JsonResponse
    {
        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        // Check if the current user is the owner of the message
        if ($thread->sender_id !== Auth::id() || $thread->sender_type !== get_class(Auth::user())) {
            return response()->json(['error' => 'You can only delete your own messages'], 403);
        }

        // Check if more than 1 minute has passed since the message was created
        if (now()->diffInMinutes($thread->created_at) > 1) {
            return response()->json(['error' => 'Messages can only be deleted within 1 minute of creation'], 403);
        }

        // Delete all attachments
        foreach ($thread->attachments as $attachment) {
            // Delete the file from storage
            if (Storage::exists($attachment->path)) {
                Storage::delete($attachment->path);
            }

            // Delete the attachment record
            $attachment->delete();
        }

        // Delete the thread
        $thread->delete();

        return response()->json(['message' => 'Thread message deleted successfully']);
    }

    /**
     * Update a thread message.
     */
    public function update(Request $request, Task $task, TaskThread $thread): JsonResponse
    {
        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        // Only the creator of the comment can edit it.
        if ($thread->sender_id !== Auth::id() || $thread->sender_type !== get_class(Auth::user())) {
            return response()->json(['error' => 'You can only edit your own messages'], 403);
        }

        $request->validate([
            'content' => 'required|string',
            'temp_identifier' => 'nullable|string',
        ]);

        $thread->content = $request->input('content');
        $thread->save();

        // Process any temporary attachments
        if ($request->has('temp_identifier')) {
            $this->processTemporaryAttachments($request->temp_identifier, $thread);
        }

        // After moving attachments, replace temp URLs in content with final URLs
        if ($request->filled('temp_identifier')) {
            $thread->load('attachments');
            $thread->content = $this->replaceTempUrlsInContent(
                $thread->content ?? '',
                $request->input('temp_identifier'),
                $thread->attachments
            );
            $thread->save();
        }

        $thread->load('attachments');

        // Filter out attachments already embedded in the content for response
        $content = (string) ($thread->content ?? '');
        $responseAttachments = $thread->attachments->filter(function ($attachment) use ($content) {
            $downloadUrlRel = route('attachments.download', $attachment, false);
            $downloadUrlAbs = url($downloadUrlRel);

            return strpos($content, $downloadUrlRel) === false && strpos($content, $downloadUrlAbs) === false;
        })->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        })->values();

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'content' => $thread->content,
                'sender_name' => $thread->sender_name,
                'is_current_user' => true,
                'created_at' => $thread->created_at,
                'attachments' => $responseAttachments,
            ],
        ]);
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

            // Match both encoded and unencoded basenames, absolute and relative URLs
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
