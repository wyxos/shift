<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Notifications\TaskThreadUpdated;
use App\Services\ExternalNotificationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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
                $attachments = $thread->attachments()->get()->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('attachments.download', $attachment),
                        'created_at' => $attachment->created_at,
                    ];
                });

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

        // Get the thread with attachments
        $thread->load('attachments');

        if ($request->input('type') === 'external') {
            $notificationService = new ExternalNotificationService();
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
                if (!$externalUsers->contains('id', $user->id)) {
                    $externalUsers->push($user);
                }
            });

            // Send notifications to all external users
            foreach ($externalUsers as $externalUser) {
                $url = $externalUser->url;

                $payload = [
                    'type' => 'task_thread',
                    'user_id' => $externalUser->external_id,
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'thread_id' => $thread->id,
                    'content' => $thread->content
                ];

                $response = $notificationService->sendNotification(
                    $url,
                    'thread.update',
                    $payload
                );

                // Create notification object with additional URL for email
                $notificationData = array_merge($payload, [
                    'url' => $externalUser->url . '/shift/tasks/' . $task->id . '/edit'
                ]);

                $notificationService->sendFallbackEmailIfNeeded(
                    $response,
                    $externalUser->email,
                    new TaskThreadUpdated($notificationData)
                );
            }
        }

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'content' => $thread->content,
                'sender_name' => $thread->sender_name,
                'is_current_user' => true,
                'created_at' => $thread->created_at,
                'attachments' => $thread->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('attachments.download', $attachment),
                        'created_at' => $attachment->created_at,
                    ];
                }),
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
        });

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
}
