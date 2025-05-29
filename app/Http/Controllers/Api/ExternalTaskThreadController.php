<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\Attachment;
use App\Models\ExternalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ExternalTaskThreadController extends Controller
{
    /**
     * Get all threads for a task.
     *
     * @param Task $task
     * @return \Illuminate\Http\JsonResponse
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
            ->where('type', $type)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($thread) {
                $attachments = $thread->attachments()->get()->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('api.attachments.download', $attachment),
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
                    'content' => $thread->content,
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
     * @return \Illuminate\Http\JsonResponse
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

        // Get the thread with attachments
        $thread->load('attachments');

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
                        'url' => route('api.attachments.download', $attachment),
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
     * @return \Illuminate\Http\JsonResponse
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
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('api.attachments.download', $attachment),
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

        return response()->json([
            'thread' => [
                'id' => $thread->id,
                'content' => $thread->content,
                'sender_name' => $thread->sender_name,
                'is_current_user' => $isCurrentUser,
                'created_at' => $thread->created_at,
                'attachments' => $attachments,
            ],
        ]);
    }
}
