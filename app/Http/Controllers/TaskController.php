<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\Attachment;
use App\Models\User;
use App\Jobs\NotifyExternalUser;
use App\Notifications\TaskCreationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Response;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = Task::query()
            ->with(['submitter', 'metadata', 'project.organisation', 'project.client'])
            ->where(function ($query) {
                $query
                    ->whereHas('project.projectUser', function ($query) {
                        $query->where('user_id', auth()->user()->id);
                    })
                    ->orWhereHas('project', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->orWhereHas('project.organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->orWhereHas('project.client.organisation', function ($query) {
                        $query->where('author_id', auth()->user()->id);
                    })
                    ->orWhereHasMorph('submitter', [User::class], function ($query) {
                        $query->where('users.id', auth()->user()->id);
                    });
            })
            ->latest()
            ->when(
                request('search'),
                fn($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->when(
                request('project_id'),
                fn($query) => $query->where('project_id', request('project_id'))
            )
            ->when(
                request('priority'),
                fn($query) => $query->where('priority', request('priority'))
            )
            ->when(
                request('status'),
                fn($query) => $query->where('status', request('status'))
            )
            ->paginate(10)
            ->withQueryString();

        $tasks->through(function (Task $task) {
            $task->is_external = $task->isExternallySubmitted();
            return $task;
        });

        // Get projects for the filter dropdown (same as in create method)
        $projects = Project::where(function ($query) {
            $query->where(
                fn($query) => $query
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

    // create task

    public function edit(Task $task)
    {
        $task->load(['project', 'attachments', 'externalUsers']);

        // Get all external users for the project
        $projectExternalUsers = $task->project->externalUsers;

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
                })
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
            'status' => 'nullable|string|in:pending,in-progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
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
                if (!Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file . '.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $extension;
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
                fn($query) => $query->whereHas('client.organisation', function ($query) {
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
                'projects' => $projects
            ]);
    }

    // create task

    public function store()
    {
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'status' => 'nullable|string|in:pending,in-progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
            'temp_identifier' => 'nullable|string',
            'external_user_ids' => 'nullable|array',
            'external_user_ids.*' => 'exists:external_users,id',
        ]);

        $task = Task::create([
            ...$attributes,
        ]);

        $task->submitter()->associate(auth()->user())->save();

        // Assign external users to the task if provided
        if (isset($attributes['external_user_ids']) && !empty($attributes['external_user_ids'])) {
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
                if (!Storage::exists($permanentPath)) {
                    Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file . '.meta';
                    $originalFilename = basename($file);

                    if (Storage::exists($metadataPath)) {
                        $metadata = json_decode(Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $extension;
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
     * @param Task $task
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
                !$usersToNotify->contains('id', $projectUser->user->id)) {
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
                'task' => $task
            ]);
    }

    /**
     * Update the status of a task.
     *
     * @param Task $task
     * @param Request $request
     * @return Response|RedirectResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'status' => 'required|string|in:pending,in-progress,completed,awaiting-feedback',
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return back()->with([
            'status' => $task->status,
            'message' => 'Task status updated successfully'
        ]);
    }

    /**
     * Update the priority of a task.
     *
     * @param Task $task
     * @param Request $request
     * @return Response|RedirectResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'priority' => 'required|string|in:low,medium,high',
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return back()->with([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully'
        ]);
    }
}
