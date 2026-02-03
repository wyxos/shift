<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Notifications\TaskThreadUpdated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class ExternalTaskThreadController extends Controller
{
    /**
     * Get all threads for a task.
     *
     * @param Task $task
     * @return JsonResponse
     */
    public function index(Task $task)
    {
        // Ensure the task belongs to the project specified in the request
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $externalThreads = $this->getThreadsByType($task, 'external');

        return response()->json([
            'external' => $externalThreads,
        ]);
    }

    /**
     * Get threads by type for a task.
     *
     * @param Task $task
     * @param string $type
     * @return array
     */
    private function getThreadsByType(Task $task, string $type)
    {
        return $task->threads()
            ->ofType($type)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($thread) {
                $attachments = $thread->attachments()->get()->map(function ($attachment) {
                    // Get the client's URL from request metadata or user data
                    $clientUrl = request('metadata.url') ?? request('user.url') ?? config('app.url');
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        // Return SDK-facing download URL pointing to the client's proxy route
                        'url' => rtrim($clientUrl, '/') . '/shift/api/attachments/' . $attachment->id . '/download',
                        'created_at' => $attachment->created_at,
                    ];
                });

                // Determine if the current user is the sender
                $isCurrentUser = false;
                if ($thread->sender_type === ExternalUser::class) {
                    $externalUser = $thread->sender;
                    $isCurrentUser = $externalUser &&
                        $externalUser->external_id == request('user.id') &&
                        $externalUser->environment == request('user.environment') &&
                        $externalUser->url == request('user.url');
                }

                return [
                    'id' => $thread->id,
'content' => $this->rewriteContentUrlsToTemporaryUrls($thread->content ?? ''),
                    'sender_name' => $thread->sender_name,
                    'is_current_user' => $isCurrentUser,
                    'created_at' => $thread->created_at,
                    'attachments' => $attachments,
                ];
            })
            ->toArray();
    }

    /**
     * Store a new thread message.
     *
     * @param Request $request
     * @param Task $task
     * @return JsonResponse
     */
    public function store(Request $request, Task $task)
    {
        // Ensure the task belongs to the project specified in the request
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $request->validate([
            'content' => 'required|string',
            'type' => 'required|in:internal,external',
            'temp_identifier' => 'nullable|string',
        ]);

        // Get or create the external user
        $externalUser = ExternalUser::updateOrCreate([
            'external_id' => request('user.id'),
            'environment' => request('user.environment'),
            'url' => request('user.url'),
        ], [
            'name' => request('user.name') ?? 'External User',
            'email' => request('user.email') ?? null,
        ]);

        $thread = new TaskThread([
            'task_id' => $task->id,
            'type' => 'external',
            'content' => $request->input('content'),
            'sender_name' => $externalUser->name,
        ]);

        $thread->sender()->associate($externalUser);
        $thread->save();

        // Process any temporary attachments
        if ($request->has('temp_identifier')) {
            $this->processTemporaryAttachments($request->temp_identifier, $thread);
        }

        // After moving attachments, replace temp URLs in content with final URLs (internal download route)
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

        // Collect all users who should receive the notification
        $usersToNotify = collect();

        // Add project users
        $projectUsers = $task->project->projectUser()->with('user')->get();
        foreach ($projectUsers as $projectUser) {
            if ($projectUser->user && !$usersToNotify->contains('id', $projectUser->user->id)) {
                $usersToNotify->push($projectUser->user);
            }
        }

        // Add the project author if not already included
        if ($task->project->author && !$usersToNotify->contains('id', $task->project->author->id)) {
            $usersToNotify->push($task->project->author);
        }

        // Send notification to users in chunks with delays to prevent SMTP connection issues
        if ($usersToNotify->isNotEmpty()) {
            Notification::send(
                $usersToNotify,
                new TaskThreadUpdated([
                    'type' => 'external',
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content,
                    'url' => route('tasks.edit', $task->id),
                ])
            );
        }

        // Filter out attachments already embedded in the content for response
        $content = (string) ($thread->content ?? '');
        $responseAttachments = $thread->attachments->filter(function ($attachment) use ($content) {
            // check against internal download URL present in content (relative or absolute)
            $downloadUrlRel = route('attachments.download', $attachment, false);
            $downloadUrlAbs = url($downloadUrlRel);
            return strpos($content, $downloadUrlRel) === false && strpos($content, $downloadUrlAbs) === false;
        })->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => $this->temporaryUrlForAttachment($attachment, 30),
                'created_at' => $attachment->created_at,
            ];
        })->values();

        return response()->json([
            'thread' => [
                'id' => $thread->id,
'content' => $this->rewriteContentUrlsToTemporaryUrls($thread->content ?? ''),
                'sender_name' => $thread->sender_name,
                'is_current_user' => true,
                'created_at' => $thread->created_at,
                'attachments' => $thread->attachments->map(function ($attachment) {
                    // Get the client's URL from request metadata or user data
                    $clientUrl = request('metadata.url') ?? request('user.url') ?? config('app.url');
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        // Return SDK-facing download URL pointing to the client's proxy route
                        'url' => rtrim($clientUrl, '/') . '/shift/api/attachments/' . $attachment->id . '/download',
                        'created_at' => $attachment->created_at,
                    ];
                }),
            ],
        ], 201);
    }

    /**
     * Process temporary attachments and associate them with the thread.
     *
     * @param string $tempIdentifier
     * @param TaskThread $thread
     * @return void
     */
    private function processTemporaryAttachments($tempIdentifier, TaskThread $thread)
    {
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (!Storage::exists($tempPath)) {
            return;
        }

        $files = Storage::files($tempPath);

        foreach ($files as $file) {
            // Skip metadata files
            if (str_ends_with($file, '.meta')) {
                continue;
            }

            // Get original filename from metadata
            $metadataPath = $file . '.meta';
            $originalFilename = basename($file);

            if (Storage::exists($metadataPath)) {
                $metadata = json_decode(Storage::get($metadataPath), true);
                if (isset($metadata['original_filename'])) {
                    $originalFilename = $metadata['original_filename'];
                }
            }

            // Move file to permanent storage
            $newPath = "attachments/task_threads/{$thread->id}/" . basename($file);
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
     *
     * @param Task $task
     * @param TaskThread $thread
     * @return JsonResponse
     */
    public function show(Task $task, $threadId)
    {
        // Ensure the task belongs to the project specified in the request
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $thread = TaskThread::findOrFail($threadId);

        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        $attachments = $thread->attachments()->get()->map(function ($attachment) {
            // Get the client's URL from request metadata or user data
            $clientUrl = request('metadata.url') ?? request('user.url') ?? config('app.url');
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                // Return SDK-facing download URL pointing to the client's proxy route
                'url' => rtrim($clientUrl, '/') . '/shift/api/attachments/' . $attachment->id . '/download',
                'created_at' => $attachment->created_at,
            ];
        })->values();

        // Determine if the current user is the sender
        $isCurrentUser = false;
        if ($thread->sender_type === ExternalUser::class) {
            $externalUser = $thread->sender;
            $isCurrentUser = $externalUser &&
                $externalUser->external_id == request('user.id') &&
                $externalUser->environment == request('user.environment') &&
                $externalUser->url == request('user.url');
        }

        return response()->json([
            'thread' => [
                'id' => $thread->id,
'content' => $this->rewriteContentUrlsToTemporaryUrls($thread->content ?? ''),
                'sender_name' => $thread->sender_name,
                'is_current_user' => $isCurrentUser,
                'created_at' => $thread->created_at,
                'attachments' => $attachments,
            ],
        ]);
    }
    /**
     * Replace temp attachment URLs in HTML content with final download URLs.
     */
    private function replaceTempUrlsInContent(string $content, string $tempIdentifier, $attachments): string
    {
        if (empty($content) || empty($tempIdentifier) || !$attachments || $attachments->isEmpty()) {
            return $content;
        }

        $out = $content;
        foreach ($attachments as $attachment) {
            // For persisted HTML, use internal download route; API consumers should render attachments list
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

    /**
     * Generate a temporary URL for the given attachment.
     * Falls back to the absolute download route if the disk does not support temporaryUrl.
     */
    private function temporaryUrlForAttachment(Attachment $attachment, int $minutes = 30): string
    {
        try {
            return Storage::temporaryUrl($attachment->path, now()->addMinutes($minutes));
        } catch (\Throwable $e) {
            return url(route('attachments.download', $attachment, false));
        }
    }

    /**
     * Rewrite any attachment download URLs in HTML content to temporary URLs for external consumption.
     * Supports both internal and API-style URLs, absolute and relative.
     */
    private function rewriteContentUrlsToTemporaryUrls(string $content, int $minutes = 30): string
    {
        if ($content === '') {
            return $content;
        }

        // Collect unique attachment IDs referenced in the content
        $patterns = [
            '#https?://[^\"\'<>]+/attachments/(\\d+)/download#',
            '#/attachments/(\\d+)/download#',
            '#https?://[^\"\'<>]+/shift/api/attachments/(\\d+)/download#',
            '#/shift/api/attachments/(\\d+)/download#',
        ];

        $ids = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $m)) {
                foreach ($m[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        if (!$ids) {
            return $content;
        }

        $map = Attachment::whereIn('id', $ids)->get()->keyBy('id');

        $replace = function (string $pattern, string $html) use ($map, $minutes) {
            return preg_replace_callback($pattern, function ($m) use ($map, $minutes) {
                $id = (int) $m[1];
                $attachment = $map->get($id);
                if (!$attachment) {
                    return $m[0];
                }
                try {
                    return Storage::temporaryUrl($attachment->path, now()->addMinutes($minutes));
                } catch (\Throwable $e) {
                    return url(route('attachments.download', $attachment, false));
                }
            }, $html) ?? $html;
        };

        $out = $content;
        foreach ($patterns as $pattern) {
            $out = $replace($pattern, $out);
        }

        return $out;
    }
}
