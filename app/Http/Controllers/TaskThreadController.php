<?php

namespace App\Http\Controllers;

use App\Models\ExternalUser;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\Attachment;
use App\Notifications\TaskThreadUpdated;
use Exception;
use Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class TaskThreadController extends Controller
{
    /**
     * Get all threads for a task.
     *
     * @param Task $task
     * @return JsonResponse
     */
    public function index(Task $task)
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
     *
     * @param Task $task
     * @param string $type
     * @return array
     */
    private function getThreadsByType(Task $task, string $type)
    {
        return $task->threads()
            ->where('type', $type)
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
     * @param Request $request
     * @param Task $task
     * @return JsonResponse
     * @throws ConnectionException
     */
    public function store(Request $request, Task $task)
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
            /** @var ExternalUser $externalUser */
            $externalUser = $task->submitter;

            $url = $externalUser->url;

            try {
                $response = Http::post($url . '/shift/api/notifications', [
                    'handler' => 'thread.update',
                    'payload' => [
                        'type' => 'task_thread',
                        'user_id' => $externalUser->external_id,
                        'task_id' => $task->id,
                        'task_title' => $task->title,
                        'thread_id' => $thread->id,
                        'content' => $thread->content
                    ]
                ]);

                if ($response->successful()) {
                    Log::info('Notification sent to external user', [
                        $response->json()
                    ]);

                    $isNotProduction = !$response->json('production');

                    if ($isNotProduction) {
                        Notification::route('mail', $externalUser->email)->notify(new TaskThreadUpdated([
                            'type' => 'task_thread',
                            'user_id' => $externalUser->external_id,
                            'task_id' => $task->id,
                            'task_title' => $task->title,
                            'thread_id' => $thread->id,
                            'content' => $thread->content,
                            'url' => $externalUser->url . '/shift/tasks/' . $task->id . '/edit'
                        ]));
                    }
                }
            } catch (Exception $e) {
                // Log the error or handle it as needed
                \Log::error('Failed to send notification to external user: ' . $e->getMessage());
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
    public function show(Task $task, TaskThread $thread)
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
