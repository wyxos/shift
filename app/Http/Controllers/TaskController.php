<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Jobs\NotifyExternalUser;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;

class TaskController extends Controller
{
    private function visibleTasksQuery(): Builder
    {
        $userId = auth()->id();

        return Task::query()
            ->where(function ($query) use ($userId) {
                $query
                    ->whereHas('project.projectUser', function ($query) use ($userId) {
                        $query->where('user_id', $userId);
                    })
                    ->orWhereHas('project', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHas('project.client.organisation', function ($query) use ($userId) {
                        $query->where('author_id', $userId);
                    })
                    ->orWhereHasMorph('submitter', [User::class], function ($query) use ($userId) {
                        $query->where('users.id', $userId);
                    });
            });
    }

    private function normalizeListFilter(mixed $value): array
    {
        if (is_array($value)) {
            $list = array_values(array_filter($value, fn ($item) => filled($item)));

            return array_map('strval', $list);
        }

        if (filled($value)) {
            return [strval($value)];
        }

        return [];
    }

    private function applyIndexFilters(Builder $query, array $filters): void
    {
        if (filled($filters['search'] ?? null)) {
            $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$filters['search'].'%']);
        }

        if (filled($filters['project_id'] ?? null)) {
            $query->where('project_id', $filters['project_id']);
        }

        $priorities = $this->normalizeListFilter($filters['priority'] ?? null);
        if (! empty($priorities)) {
            $query->whereIn('priority', $priorities);
        }

        $statuses = $this->normalizeListFilter($filters['status'] ?? null);
        if (! empty($statuses)) {
            $query->whereIn('status', $statuses);
        }
    }

    private function ensureTaskVisible(Task $task): void
    {
        if (! $this->visibleTasksQuery()->whereKey($task->id)->exists()) {
            abort(404);
        }
    }

    /**
     * Move all temp attachments for a given identifier to this task, preserving basenames
     * so we can safely swap temp URLs in rich HTML.
     */
    private function persistTempAttachmentsForTask(Task $task, string $tempIdentifier)
    {
        $tempPath = "temp_attachments/{$tempIdentifier}";

        if (! Storage::exists($tempPath)) {
            return collect();
        }

        $files = Storage::files($tempPath);
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

        Storage::deleteDirectory($tempPath);

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

    public function index()
    {
        $query = $this->visibleTasksQuery()
            ->with(['submitter', 'metadata', 'project.organisation', 'project.client'])
            ->latest();

        $this->applyIndexFilters($query, request()->only(['search', 'project_id', 'priority', 'status']));

        $tasks = $query->paginate(10)->withQueryString();

        $tasks->through(function (Task $task) {
            $task->is_external = $task->isExternallySubmitted();

            return $task;
        });

        // Get projects for the filter dropdown (same as in create method)
        $projects = Project::where(function ($query) {
            $query->where(
                fn ($query) => $query
                    ->whereHas('client.organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })->orWhereHas('organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->orWhere('author_id', auth()->user()->id)
                    ->orWhereHas('projectUser', function ($query) {
                        $query->where('user_id', auth()->user()->id);
                    })
            );
        })->get();

        return inertia('Tasks/Index')
            ->with([
                'filters' => request()->only(['search', 'project_id', 'priority', 'status']),
                'tasks' => $tasks,
                'projects' => $projects,
            ]);
    }

    public function indexV2()
    {
        $defaultStatuses = ['pending', 'in-progress', 'awaiting-feedback'];

        $selectedStatuses = $this->normalizeListFilter(request('status'));
        if (empty($selectedStatuses)) {
            $selectedStatuses = $defaultStatuses;
        }

        $query = $this->visibleTasksQuery()
            ->latest();

        // V2 (for now) is intentionally "list + filters" only.
        // Keep server filtering limited to status (like the SDK UI),
        // and handle search/priority filtering client-side.
        $query->whereIn('status', $selectedStatuses);

        $tasks = $query->get(['id', 'title', 'status', 'priority']);

        return inertia('Tasks/IndexV2')
            ->with([
                'filters' => [
                    'status' => $selectedStatuses,
                ],
                'tasks' => $tasks,
            ]);
    }

    public function showV2(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $task->load(['submitter', 'attachments']);

        $isOwner = $task->submitter_type === User::class && $task->submitter_id === auth()->id();

        return response()->json([
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'description' => $task->description,
            'created_at' => $task->created_at?->toIso8601String(),
            'is_owner' => $isOwner,
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'attachments' => $task->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'original_filename' => $attachment->original_filename,
                    'path' => $attachment->path,
                    'url' => route('attachments.download', $attachment),
                ];
            })->values(),
        ]);
    }

    public function updateV2(Request $request, Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $isOwner = $task->submitter_type === User::class && $task->submitter_id === auth()->id();
        if (! $isOwner) {
            return response()->json(['error' => 'You cannot edit this task'], 403);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
        ]);

        $task->title = $attributes['title'];
        $task->priority = $attributes['priority'];
        $task->description = $attributes['description'] ?? null;
        $task->save();

        if (! empty($attributes['deleted_attachment_ids'])) {
            foreach ($attributes['deleted_attachment_ids'] as $attachmentId) {
                $attachment = Attachment::find($attachmentId);

                if ($attachment && $attachment->attachable_id === $task->id && $attachment->attachable_type === Task::class) {
                    if (Storage::exists($attachment->path)) {
                        Storage::delete($attachment->path);
                    }
                    $attachment->delete();
                }
            }
        }

        if (! empty($attributes['temp_identifier'])) {
            $created = $this->persistTempAttachmentsForTask($task, $attributes['temp_identifier']);

            if ($task->description) {
                $task->description = $this->replaceTempUrlsInContent($task->description, $attributes['temp_identifier'], $created);
                $task->save();
            }
        }

        $task->load('attachments');

        return response()->json([
            'ok' => true,
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'status' => $task->status,
                'description' => $task->description,
                'attachments' => $task->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('attachments.download', $attachment),
                    ];
                })->values(),
            ],
        ]);
    }

    public function destroyV2(Task $task): JsonResponse
    {
        $this->ensureTaskVisible($task);

        $task->delete();

        return response()->json(['ok' => true]);
    }

    // create task

    public function edit(Task $task)
    {
        $task->load(['project', 'attachments', 'externalUsers', 'submitter']);

        // Check if task was submitted by an external user
        $isExternallySubmitted = $task->isExternallySubmitted();

        if ($isExternallySubmitted) {
            // If submitted by external user, only show external users from the same environment
            $submitterEnvironment = $task->submitter->environment;
            $projectExternalUsers = $task->project->externalUsers()
                ->where('environment', $submitterEnvironment)
                ->get();
        } else {
            // If submitted by internal user, show all external users from the project
            $projectExternalUsers = $task->project->externalUsers;
        }

        // Get the IDs of external users that have access to this task
        $taskExternalUserIds = $task->externalUsers->pluck('id')->toArray();

        return inertia('Tasks/Edit')
            ->with([
                'task' => $task,
                'project' => $task->project,
                'projectExternalUsers' => $projectExternalUsers,
                'taskExternalUserIds' => $taskExternalUserIds,
                'attachments' => $task->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'original_filename' => $attachment->original_filename,
                        'path' => $attachment->path,
                        'url' => route('attachments.download', $attachment),
                    ];
                }),
            ]);
    }

    // edit task

    public function destroy(Task $task)
    {
        $task->delete();

        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    // delete route

    public function update(Task $task)
    {
        // Handle web form submission
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
            'external_user_ids' => 'nullable|array',
            'external_user_ids.*' => 'exists:external_users,id',
        ]);

        $task->update([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? $task->description,
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

        // Update external users access
        if (isset($attributes['external_user_ids'])) {
            $task->externalUsers()->sync($attributes['external_user_ids']);
        }

        // Handle deleted attachments
        if (isset($attributes['deleted_attachment_ids']) && count($attributes['deleted_attachment_ids']) > 0) {
            foreach ($attributes['deleted_attachment_ids'] as $attachmentId) {
                $attachment = Attachment::find($attachmentId);

                if ($attachment && $attachment->attachable_id === $task->id && $attachment->attachable_type === Task::class) {
                    // Delete the file if it exists
                    if (Storage::exists($attachment->path)) {
                        Storage::delete($attachment->path);
                    }

                    // Delete the attachment record
                    $attachment->delete();
                }
            }
        }

        // Handle new attachments if temp_identifier is provided
        if (isset($attributes['temp_identifier'])) {
            $tempIdentifier = $attributes['temp_identifier'];
            $tempPath = "temp_attachments/{$tempIdentifier}";

            // Check if temp directory exists
            if (Storage::exists($tempPath)) {
                // Get all files in the temp directory
                $files = Storage::files($tempPath);

                // Create permanent directory if it doesn't exist
                $permanentPath = "attachments/{$task->id}";
                if (! Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file.'.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME).'_'.uniqid().'.'.$extension;
                    $newPath = "{$permanentPath}/{$storedFilename}";

                    // Move the file
                    Storage::move($file, $newPath);

                    // Create attachment record
                    Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // put task

    public function create()
    {
        $projects = Project::where(function ($query) {
            $query->where(
                fn ($query) => $query->whereHas('client.organisation', function ($query) {
                    $query->where('author_id', auth()->user()->id);
                })->orWhereHas('organisation', function ($query) {
                    $query->where('author_id', auth()->user()->id);
                })
                    ->orWhere('author_id', auth()->user()->id)
            )
                ->orWhereHas('projectUser', function ($query) {
                    $query->where('user_id', auth()->user()->id);
                });
        })->get();

        // Load external users for each project
        $projects->each(function ($project) {
            $project->load('externalUsers');
        });

        return inertia('Tasks/Create')
            ->with([
                'projects' => $projects,
            ]);
    }

    // create task

    public function store()
    {
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'temp_identifier' => 'nullable|string',
            'external_user_ids' => 'nullable|array',
            'external_user_ids.*' => 'exists:external_users,id',
        ]);

        $task = Task::create([
            ...$attributes,
        ]);

        $task->submitter()->associate(auth()->user())->save();

        // Assign external users to the task if provided
        if (isset($attributes['external_user_ids']) && ! empty($attributes['external_user_ids'])) {
            $task->externalUsers()->attach($attributes['external_user_ids']);
        }

        // Send notification to project owner and users with access to the project
        $this->sendTaskCreationNotifications($task);

        // Handle attachments if temp_identifier is provided
        if (isset($attributes['temp_identifier'])) {
            $tempIdentifier = $attributes['temp_identifier'];
            $tempPath = "temp_attachments/{$tempIdentifier}";

            // Check if temp directory exists
            if (Storage::exists($tempPath)) {
                // Get all files in the temp directory
                $files = Storage::files($tempPath);

                // Create permanent directory if it doesn't exist
                $permanentPath = "attachments/{$task->id}";
                if (! Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file.'.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME).'_'.uniqid().'.'.$extension;
                    $newPath = "{$permanentPath}/{$storedFilename}";

                    // Move the file
                    Storage::move($file, $newPath);

                    // Create attachment record
                    Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        return redirect()->route('tasks.index')->with('success', 'Task created successfully.');
    }

    /**
     * Send task creation notifications to project owner and users with access to the project.
     * Excludes the creator of the task from receiving notifications.
     * Also notifies external users attached to the task.
     *
     * @return void
     */
    protected function sendTaskCreationNotifications(Task $task)
    {
        // Load the project with its relationships
        $project = $task->project()->with(['author', 'projectUser.user'])->first();

        // Collect all users who should receive the notification
        $usersToNotify = collect();

        // Get the creator's ID (if it's a User)
        $creatorId = null;
        if ($task->submitter_type === User::class) {
            $creatorId = $task->submitter_id;
        }

        // Add the project owner (author) if they're not the creator
        if ($project->author && $project->author->id !== $creatorId) {
            $usersToNotify->push($project->author);
        }

        // Add all users with access to the project, except the creator
        foreach ($project->projectUser as $projectUser) {
            if ($projectUser->user &&
                $projectUser->user->id !== $creatorId &&
                ! $usersToNotify->contains('id', $projectUser->user->id)) {
                $usersToNotify->push($projectUser->user);
            }
        }

        if ($usersToNotify->isNotEmpty()) {
            Notification::send($usersToNotify, new TaskCreationNotification($task));
        }

        // Notify external users attached to the task
        $externalUsers = $task->externalUsers;

        if ($externalUsers->isNotEmpty()) {
            foreach ($externalUsers as $externalUser) {
                NotifyExternalUser::dispatch($externalUser->id, $task->id);
            }
        }
    }

    public function show(Task $task)
    {
        return inertia('Tasks/Show')
            ->with([
                'task' => $task,
            ]);
    }

    /**
     * Update the status of a task.
     *
     * @return Response|RedirectResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $this->ensureTaskVisible($task);

        $validatedData = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return back()->with([
            'status' => $task->status,
            'message' => 'Task status updated successfully',
        ]);
    }

    /**
     * Update the priority of a task.
     *
     * @return Response|RedirectResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        $this->ensureTaskVisible($task);

        $validatedData = $request->validate([
            'priority' => ['required', Rule::enum(TaskPriority::class)],
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return back()->with([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully',
        ]);
    }
}
