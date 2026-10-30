<?php

namespace App\Http\Controllers;

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

trait SerializesPortalTasks
{
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            ...$this->permissions->projectCapabilities($project, auth()->id()),
            'environments' => $project->environments
                ->sortBy('environment')
                ->values()
                ->map(fn ($environment) => [
                    'key' => $environment->environment,
                    'label' => $this->projectEnvironmentService->label($environment->environment),
                    'url' => $environment->url,
                ])
                ->all(),
        ];
    }

    private function serializeTaskProject(?Project $project): ?array
    {
        if (! $project) {
            return null;
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
        ];
    }

    private function serializeTaskType(Task $task): array
    {
        if (filled($task->error_signature)) {
            return [
                'type' => 'app_error',
                'type_label' => $task->error_component === 'shift_sdk' ? 'SDK error' : 'App error',
            ];
        }

        return [
            'type' => 'task',
            'type_label' => 'Task',
        ];
    }

    private function serializeAttachments(Task $task): array
    {
        return $task->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'original_filename' => $attachment->original_filename,
                'path' => $attachment->path,
                'url' => route('attachments.download', $attachment),
            ];
        })->values()->all();
    }

    private function serializeCollaborators(Task $task): array
    {
        return [
            'internal_collaborators' => $task->internalCollaborators
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'external_collaborators' => $task->externalCollaborators
                ->map(fn (ExternalIdentity $user) => [
                    'id' => $user->external_id,
                    'environment' => $user->environment,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
        ];
    }

    private function taskEnvironment(Task $task): ?string
    {
        return $task->metadata?->environment;
    }

    private function serializeTaskDetail(Task $task, bool $includeOwnerState = true): array
    {
        $payload = [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'project' => $this->serializeTaskProject($task->project),
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'description' => $task->description,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'environment' => $this->taskEnvironment($task),
            'phase' => $task->phase(),
            'finalized' => ! $task->isRequirementPhase(),
            'requirement_status' => $task->requirementStatus(),
            'submitted_title' => $task->metadata?->submitted_title,
            'submitted_description' => $task->metadata?->submitted_description,
            'finalized_at' => $task->metadata?->finalized_at?->toIso8601String(),
            ...$this->serializeTaskType($task),
            'error_signature' => $task->error_signature,
            'error_source' => $task->error_source,
            'error_component' => $task->error_component,
            'error_environment' => $task->error_environment,
            'error_release' => $task->error_release,
            'error_git_sha' => $task->error_git_sha,
            'error_exception_class' => $task->error_exception_class,
            'error_name' => $task->error_name,
            'error_culprit_file' => $task->error_culprit_file,
            'error_culprit_line' => $task->error_culprit_line,
            'error_culprit_function' => $task->error_culprit_function,
            'error_occurrences_count' => $task->error_occurrences_count,
            'error_first_seen_at' => $task->error_first_seen_at?->toIso8601String(),
            'error_last_seen_at' => $task->error_last_seen_at?->toIso8601String(),
            'submitter' => $task->submitter ? [
                'name' => $task->submitter->name ?? null,
                'email' => $task->submitter->email ?? null,
            ] : null,
            'attachments' => $this->serializeAttachments($task),
            ...$this->serializeCollaborators($task),
        ];

        if ($includeOwnerState) {
            $payload['is_owner'] = $task->submitter_type === User::class && $task->submitter_id === auth()->id();
            $payload = [
                ...$payload,
                ...$this->permissions->taskCapabilities($task, auth()->id()),
            ];
        }

        return $payload;
    }
}
