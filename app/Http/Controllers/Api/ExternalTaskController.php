<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExternalTaskController extends Controller
{
    /**
     * Display a listing of the tasks.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $tasks = Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->whereHas('project', fn($query) => $query->where('token', request('project')))
            ->whereHasMorph('submitter', [ExternalUser::class], function ($query) {
                $query
                    ->where('environment', request()->offsetGet('user.environment'))
                    ->where('url', request()->offsetGet('user.url'))
                    ->where('external_id', request()->offsetGet('user.id'));
            })
            ->latest()
            ->when(
                request('search'),
                fn ($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->paginate(10)
            ->withQueryString();

        return response()->json($tasks);
    }

    /**
     * Display the specified task.
     *
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Task $task)
    {
        // Ensure the task belongs to the project specified in the request
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $task->load(['submitter', 'metadata', 'project', 'attachments']);

        // Format the attachments for the response
        $formattedAttachments = $task->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
                'created_at' => $attachment->created_at,
            ];
        });

        // Add the formatted attachments to the task
        $task = $task->toArray();
        $task['attachments'] = $formattedAttachments;

        return response()->json($task);
    }

    /**
     * Store a newly created task in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project' => 'required|exists:projects,token',
            'priority' => 'nullable|string|in:low,medium,high',
            'status' => 'nullable|string|in:pending,in-progress,completed',
            'user.id' => 'nullable',
            'user.name' => 'nullable|string|max:255',
            'user.email' => 'nullable|email',
            'user.environment' => 'nullable|string|max:255',
            'user.url' => 'nullable|url',
            'metadata.url' => 'required|url',
            'metadata.environment' => 'required|string|max:255',
            'temp_identifier' => 'nullable|string',
        ]);

        $task = Task::create([
            ...$attributes,
            'project_id' => Project::where('token', $attributes['project'])->firstOrFail()->id,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'medium',
        ]);

        if(isset($attributes['user'])) {
            $externalUser = ExternalUser::updateOrCreate([
                'external_id' => $attributes['user']['id'],
                'environment' => $attributes['user']['environment'],
                'url' => $attributes['user']['url'],
            ], [
                'name' => $attributes['user']['name'] ?? null,
                'email' => $attributes['user']['email'],
                'project_id' => $task->project_id, // Set project_id based on the task's project
            ]);

            $task->submitter()->associate($externalUser)->save();
        }

        $task->metadata()->create([
            'url' => request('metadata.url'),
            'environment' => request('metadata.environment'),
        ]);

        // Send notifications to project users
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

                    // Delete metadata file
                    if (Storage::exists($metadataPath)) {
                        Storage::delete($metadataPath);
                    }
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        return response()->json($task, 201);
    }

    /**
     * Update the specified task in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Task  $task
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Task $task)
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        if(!$task->submitter || $task->submitter->external_id !== request('user.id')) {
            return response()->json(['error' => 'Unauthorized to update this task'], 403);
        }

        $attributes = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high',
            'status' => 'nullable|string|in:pending,in-progress,completed',
            'temp_identifier' => 'nullable|string',
            'deleted_attachment_ids' => 'nullable|array',
            'deleted_attachment_ids.*' => 'integer|exists:attachments,id',
        ]);

        $task->update([
            ...$attributes,
            'status' => $attributes['status'] ?? $task->status,
            'priority' => $attributes['priority'] ?? $task->priority,
        ]);

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

                    // Delete metadata file
                    if (Storage::exists($metadataPath)) {
                        Storage::delete($metadataPath);
                    }
                }

                // Remove the temp directory
                Storage::deleteDirectory($tempPath);
            }
        }

        return response()->json($task, 200);
    }

    /**
     * Remove the specified task from storage.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function destroy(Task $task, Request $request)
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        if(!$task->submitter || $task->submitter->external_id !== request('user.id')) {
            return response()->json(['error' => 'Unauthorized to delete this task'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Task deleted successfully'], 200);
    }

    /**
     * Toggle the status of the specified task.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus(Task $task, Request $request)
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $validatedData = $request->validate([
            'status' => 'required|string|in:pending,in-progress,completed',
        ]);

        $task->status = $validatedData['status'];
        $task->save();

        return response()->json([
            'status' => $task->status,
            'message' => 'Task status updated successfully'
        ]);
    }

    /**
     * Toggle the priority of the specified task.
     *
     * @param  \App\Models\Task  $task
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function togglePriority(Task $task, Request $request)
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        $validatedData = $request->validate([
            'priority' => 'required|string|in:low,medium,high',
        ]);

        $task->priority = $validatedData['priority'];
        $task->save();

        return response()->json([
            'priority' => $task->priority,
            'message' => 'Task priority updated successfully'
        ]);
    }

    /**
     * Send task creation notifications to project owner and users with access to the project.
     * For external tasks, all relevant users should receive notifications.
     *
     * @param Task $task
     * @return void
     */
    private function sendTaskCreationNotifications(Task $task)
    {
        // Load the project with its relationships
        $project = $task->project()->with(['author', 'projectUser.user'])->first();

        // Collect all users who should receive the notification
        $usersToNotify = collect();

        // Add the project owner (author)
        if ($project->author) {
            $usersToNotify->push($project->author);
        }

        // Add all users with access to the project
        foreach ($project->projectUser as $projectUser) {
            if ($projectUser->user && !$usersToNotify->contains('id', $projectUser->user->id)) {
                $usersToNotify->push($projectUser->user);
            }
        }

        // Send notification to each user
        $notification = new TaskCreationNotification($task);
        foreach ($usersToNotify as $user) {
            $user->notify($notification);
        }
    }
}
