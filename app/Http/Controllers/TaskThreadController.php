<?php

namespace App\Http\Controllers;

use App\Enums\TaskCollaboratorKind;
use App\Enums\TaskThreadAudience;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use App\Services\TaskThreadAudienceService;
use App\Services\TaskThreadMentionService;
use App\Services\TaskThreadNotificationService;
use App\Services\TemporaryAttachmentStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskThreadController extends Controller
{
    public function __construct(
        private readonly TaskThreadAudienceService $audiences,
        private readonly TaskThreadMentionService $mentions,
        private readonly TaskThreadNotificationService $taskThreadNotificationService,
        private readonly TemporaryAttachmentStorage $temporaryAttachments,
    ) {}

    private function ensureTaskVisible(Task $task): void
    {
        if (! Task::query()->visibleTo(Auth::id())->whereKey($task->id)->exists()) {
            abort(404);
        }
    }

    /**
     * Get all threads for a task.
     */
    public function index(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $internalThreads = $this->getThreadsByType($task, 'internal');
        $externalThreads = $this->getThreadsByType($task, 'external');

        return response()->json([
            'internal' => $internalThreads,
            'external' => $externalThreads,
            'threads' => collect($internalThreads)
                ->concat($externalThreads)
                ->sortBy([
                    ['created_at', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->all(),
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
            ->orderBy('id', 'asc')
            ->with(['attachments', 'mentions.user:id,name', 'mentions.externalUser:id,external_id,name'])
            ->get()
            ->map(function (TaskThread $thread) use ($task) {
                // Filter out attachments that are already embedded in the content
                $content = (string) ($thread->content ?? '');
                $attachments = $thread->attachments
                    ->filter(function ($attachment) use ($content) {
                        $downloadUrlRel = route('attachments.download', $attachment, false);

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
                    'is_current_user' => $this->isCurrentUserThread($task, $thread),
                    'created_at' => $thread->created_at,
                    'attachments' => $attachments,
                    'audience' => $this->audiences->audience($thread)->value,
                    'mentions' => $this->mentions->serialize($thread),
                ];
            })
            ->toArray();
    }

    private function isCurrentUserThread(Task $task, TaskThread $thread): bool
    {
        if ($task->error_signature && $thread->sender_name === 'SHIFT error intake') {
            return false;
        }

        $user = Auth::user();

        return $user !== null && $thread->sender_id === $user->getKey() && $thread->sender_type === get_class($user);
    }

    /**
     * Store a new thread message.
     *
     * @throws ConnectionException
     */
    public function store(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $request->validate([
            'content' => 'required|string',
            'type' => 'required|in:internal,external',
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'mentions' => ['sometimes', 'array', 'max:50'],
            'mentions.*.kind' => ['required', Rule::enum(TaskCollaboratorKind::class)],
            'mentions.*.id' => ['required', $this->mentionIdentityRule()],
            'add_collaborators' => ['sometimes', 'array', 'max:20'],
            'add_collaborators.*.kind' => ['required', Rule::enum(TaskCollaboratorKind::class)],
            'add_collaborators.*.id' => ['required', $this->mentionIdentityRule()],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $audience = TaskThreadAudience::fromStoredType($request->string('type')->toString());
        $content = (string) $this->sanitizeRichContent($request->input('content'));
        $this->audiences->assertContentMayBeShared($task, $audience, $content);
        $resolvedMentions = $this->mentions->resolve(
            $task,
            $user,
            $audience,
            $request->input('mentions', []),
            $request->input('add_collaborators', []),
        );
        $content = $this->mentions->normalizeContent($content, $resolvedMentions);

        $thread = DB::transaction(function () use ($task, $user, $audience, $content, $resolvedMentions): TaskThread {
            $thread = new TaskThread([
                'task_id' => $task->id,
                'type' => $audience->storedType(),
                'content' => $content,
                'sender_name' => $user->name,
            ]);

            $thread->sender()->associate($user);
            $thread->save();
            $this->mentions->persist($task, $thread, $resolvedMentions);

            return $thread;
        });

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
        $thread->load(['attachments', 'mentions.user:id,name', 'mentions.externalUser:id,external_id,name']);

        $this->taskThreadNotificationService->send($task, $thread);

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
                'audience' => $audience->value,
                'mentions' => $this->mentions->serialize($thread),
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

        $tempPath = $this->temporaryAttachments->ownedDirectory($tempIdentifier, Auth::id());
        if ($tempPath === null) {
            return;
        }

        $files = $this->temporaryAttachments->ownedFiles($tempIdentifier, Auth::id());

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
        $this->temporaryAttachments->deleteOwnedDirectory($tempIdentifier, Auth::id());
    }

    /**
     * Get a specific thread message.
     */
    public function show(Task $task, TaskThread $thread): JsonResponse
    {
        $this->ensureTaskVisible($task);

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
                'audience' => $this->audiences->audience($thread)->value,
                'mentions' => $this->mentions->serialize($thread),
            ],
        ]);
    }

    /**
     * Delete a thread message.
     */
    public function destroy(Task $task, TaskThread $thread): JsonResponse
    {
        $this->ensureTaskVisible($task);

        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        // Check if the current user is the owner of the message
        if ($thread->sender_id !== Auth::id() || $thread->sender_type !== get_class(Auth::user())) {
            return response()->json(['error' => 'You can only delete your own messages'], 403);
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
        $this->ensureTaskVisible($task);

        if ($thread->task_id !== $task->id) {
            return response()->json(['error' => 'Thread does not belong to this task'], 403);
        }

        // Only the creator of the comment can edit it.
        if ($thread->sender_id !== Auth::id() || $thread->sender_type !== get_class(Auth::user())) {
            return response()->json(['error' => 'You can only edit your own messages'], 403);
        }

        $request->validate([
            'content' => 'required|string',
            'temp_identifier' => ['nullable', 'string', TemporaryAttachmentStorage::IDENTIFIER_RULE],
            'type' => ['nullable', 'in:internal,external'],
            'mentions' => ['sometimes', 'array', 'max:50'],
            'mentions.*.kind' => ['required', Rule::enum(TaskCollaboratorKind::class)],
            'mentions.*.id' => ['required', $this->mentionIdentityRule()],
            'add_collaborators' => ['prohibited'],
        ]);

        if ($request->filled('type') && $request->string('type')->toString() !== $thread->type) {
            throw ValidationException::withMessages([
                'type' => 'Editing a message cannot change its audience.',
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $audience = $this->audiences->audience($thread);
        $content = (string) $this->sanitizeRichContent($request->input('content'));
        $this->audiences->assertContentMayBeShared($task, $audience, $content);
        $resolvedMentions = $request->has('mentions')
            ? $this->mentions->resolve(
                $task,
                $user,
                $audience,
                $request->input('mentions', []),
                [],
                false,
            )
            : null;
        $content = $this->mentions->normalizeContent(
            $content,
            $resolvedMentions ?? $this->mentions->resolvedForThread($thread),
        );

        DB::transaction(function () use ($thread, $content, $resolvedMentions): void {
            $thread->content = $content;
            $thread->save();

            if ($resolvedMentions !== null) {
                $this->mentions->replace($thread, $resolvedMentions);
            }
        });

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

        $thread->load(['attachments', 'mentions.user:id,name', 'mentions.externalUser:id,external_id,name']);

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
                'audience' => $audience->value,
                'mentions' => $this->mentions->serialize($thread),
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

    private function mentionIdentityRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ((! is_int($value) && ! is_string($value))
                || trim((string) $value) === ''
                || mb_strlen((string) $value) > 255) {
                $fail("The {$attribute} field must contain a valid collaborator identity.");
            }
        };
    }
}
