<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\ExternalUser;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\TaskCreationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExternalTaskController extends Controller
{
    /**
     * Display a listing of the tasks.
     */
    public function index(): JsonResponse
    {
        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            $externalUser = ExternalUser::create([
                'external_id' => request()->offsetGet('user.id'),
                'name' => request()->offsetGet('user.name'),
                'email' => request()->offsetGet('user.email'),
                'environment' => request()->offsetGet('user.environment'),
                'url' => request()->offsetGet('user.url'),
            ]);
        }

        $tasks = Task::query()
            ->with(['submitter', 'metadata', 'project'])
            ->whereHas('project', fn($query) => $query->where('token', request('project')))
            ->where(function ($query) use ($externalUser) {
                // Tasks where the external user is the submitter
                $query->whereHasMorph('submitter', [ExternalUser::class], function ($query) use ($externalUser) {
                    $query->where('external_users.id', $externalUser->id);
                })
                    // OR tasks where the external user has been granted access
                    ->orWhereHas('externalUsers', function ($query) use ($externalUser) {
                        $query->where('external_users.id', $externalUser->id);
                    });
            })
            ->latest()
            ->when(
                request('search'),
                fn($query) => $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . request('search') . '%'])
            )
            ->when(
                request('status'),
                fn($query) => $query->where('status', request('status'))
            )
            ->paginate(10)
            ->withQueryString();

        return response()->json($tasks);
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task): JsonResponse
    {
        // Ensure the task belongs to the project specified in the request
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        // Check if the external user is the submitter or has been granted access
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

        if (!$isSubmitter && !$hasAccess) {
            return response()->json(['error' => 'Unauthorized to view this task'], 403);
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
     */
    public function store(Request $request): JsonResponse
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
            'metadata.url' => 'nullable|url',
            'metadata.environment' => 'nullable|string|max:255',
            'temp_identifier' => 'nullable|string',
        ]);

        $task = Task::create([
            ...$attributes,
            'project_id' => Project::where('token', $attributes['project'])->firstOrFail()->id,
            'status' => $attributes['status'] ?? 'pending',
            'priority' => $attributes['priority'] ?? 'medium',
        ]);

        if (isset($attributes['user'])) {
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

        if (isset($attributes['metadata'])) {
            $task->metadata()->create([
                'url' => request('metadata.url'),
                'environment' => request('metadata.environment'),
            ]);
        }

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
     * Send task creation notifications to project owner and users with access to the project.
     * For external tasks, all relevant users should receive notifications.
     */
    private function sendTaskCreationNotifications(Task $task): void
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

        if ($usersToNotify->isNotEmpty()) {
            Notification::send(
                $usersToNotify,
                new TaskCreationNotification($task, route('tasks.edit', ['task' => $task->id]))
            );
        }
    }

    /**
     * Update the specified task in storage.
     */
    public function update(Request $request, Task $task): JsonResponse|RedirectResponse
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        // Check if the external user is the submitter or has been granted access
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

        if (!$isSubmitter && !$hasAccess) {
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
     */
    public function destroy(Task $task, Request $request): JsonResponse|RedirectResponse
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        // Check if the external user is the submitter or has been granted access
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

        if (!$isSubmitter && !$hasAccess) {
            return response()->json(['error' => 'Unauthorized to delete this task'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Task deleted successfully'], 200);
    }

    /**
     * Toggle the status of the specified task.
     */
    public function toggleStatus(Task $task, Request $request): JsonResponse
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        // Check if the external user is the submitter or has been granted access
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

        if (!$isSubmitter && !$hasAccess) {
            return response()->json(['error' => 'Unauthorized to update this task status'], 403);
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
     */
    public function togglePriority(Task $task, Request $request): JsonResponse
    {
        if ($task->project->token !== request('project')) {
            return response()->json(['error' => 'Task not found in the specified project'], 404);
        }

        // Get the current external user
        $externalUser = ExternalUser::where('external_id', request()->offsetGet('user.id'))
            ->where('environment', request()->offsetGet('user.environment'))
            ->where('url', request()->offsetGet('user.url'))
            ->first();

        if (!$externalUser) {
            return response()->json(['error' => 'External user not found'], 404);
        }

        // Check if the external user is the submitter or has been granted access
        $isSubmitter = $task->submitter_type === ExternalUser::class && $task->submitter_id === $externalUser->id;
        $hasAccess = $task->externalUsers()->where('external_users.id', $externalUser->id)->exists();

        if (!$isSubmitter && !$hasAccess) {
            return response()->json(['error' => 'Unauthorized to update this task priority'], 403);
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
}
