<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function index()
    {
        $tasks = \App\Models\Task::query()
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

        return inertia('Tasks/Create')
            ->with([
                'projects' => $projects
            ]);
    }

    // edit task
    public function edit(\App\Models\Task $task)
    {
        $task->load(['project', 'attachments']);

        return inertia('Tasks/Edit')
            ->with([
                'task' => $task,
                'project' => $task->project,
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

    // delete route
    public function destroy(\App\Models\Task $task)
    {
        $task->delete();
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    // put task
    public function update(\App\Models\Task $task)
    {
        // Handle web form submission
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
        ]);

        $task->update([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? $task->description,
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

        // Handle deleted attachments
        if (isset($attributes['deleted_attachment_ids']) && count($attributes['deleted_attachment_ids']) > 0) {
            foreach ($attributes['deleted_attachment_ids'] as $attachmentId) {
                $attachment = \App\Models\Attachment::find($attachmentId);

                if ($attachment && $attachment->attachable_id === $task->id && $attachment->attachable_type === Task::class) {
                    // Delete the file if it exists
                    if (\Illuminate\Support\Facades\Storage::exists($attachment->path)) {
                        \Illuminate\Support\Facades\Storage::delete($attachment->path);
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
            if (\Illuminate\Support\Facades\Storage::exists($tempPath)) {
                // Get all files in the temp directory
                $files = \Illuminate\Support\Facades\Storage::files($tempPath);

                // Create permanent directory if it doesn't exist
                $permanentPath = "attachments/{$task->id}";
                if (!\Illuminate\Support\Facades\Storage::exists($permanentPath)) {
                    \Illuminate\Support\Facades\Storage::makeDirectory($permanentPath);
                }

                // Move each file to the permanent location and create attachment records
                foreach ($files as $file) {
                    // Skip metadata files
                    if (\Illuminate\Support\Str::endsWith($file, '.meta')) {
                        continue;
                    }

                    // Try to get original filename from metadata
                    $metadataPath = $file . '.meta';
                    $originalFilename = basename($file);

                    if (\Illuminate\Support\Facades\Storage::exists($metadataPath)) {
                        $metadata = json_decode(\Illuminate\Support\Facades\Storage::get($metadataPath), true);
                        if (isset($metadata['original_filename'])) {
                            $originalFilename = $metadata['original_filename'];
                        }
                    }

                    // Generate a unique filename for storage
                    $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
                    $storedFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $extension;
                    $newPath = "{$permanentPath}/{$storedFilename}";

                    // Move the file
                    \Illuminate\Support\Facades\Storage::move($file, $newPath);

                    // Create attachment record
                    \App\Models\Attachment::create([
                        'attachable_id' => $task->id,
                        'attachable_type' => Task::class,
                        'original_filename' => $originalFilename,
                        'path' => $newPath,
                    ]);
                }

                // Remove the temp directory
                \Illuminate\Support\Facades\Storage::deleteDirectory($tempPath);
            }
        }

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    // create task
    public function store()
    {
        $attributes = request()->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'status' => 'nullable|string|in:pending,in_progress,completed',
            'priority' => 'nullable|string|in:low,medium,high',
            'temp_identifier' => 'nullable|string',
        ]);

        $task = \App\Models\Task::create([
            ...$attributes,
        ]);

        $task->submitter()->associate(auth()->user())->save();

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
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        $validatedData = $request->validate([
            'status' => 'required|string|in:pending,in_progress,completed',
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
     * @return \Inertia\Response|\Illuminate\Http\RedirectResponse
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
