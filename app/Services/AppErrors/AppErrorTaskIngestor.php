<?php

namespace App\Services\AppErrors;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskErrorOccurrence;
use App\Models\User;
use App\Notifications\AppErrorReportedNotification;
use App\Services\ProjectAppErrorNotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class AppErrorTaskIngestor
{
    public function __construct(
        private readonly AppErrorSignature $signature,
        private readonly AppErrorScrubber $scrubber,
        private readonly ProjectAppErrorNotificationService $notifications,
    ) {}

    /**
     * @return array{0: Task, 1: TaskErrorOccurrence}
     */
    public function record(Project $project, User $sender, array $payload): array
    {
        $signature = $this->signature->build($project, $payload);
        $receivedAt = now();
        $occurredAt = $this->occurredAt($payload);
        $scrubbedPayload = $this->scrubber->scrubArray($payload);
        $message = $this->message($payload);

        try {
            return $this->recordOccurrence($project, $sender, $signature, $receivedAt, $occurredAt, $scrubbedPayload, $message);
        } catch (UniqueConstraintViolationException) {
            return $this->recordOccurrence($project, $sender, $signature, $receivedAt, $occurredAt, $scrubbedPayload, $message);
        }
    }

    private function recordOccurrence(
        Project $project,
        User $sender,
        array $signature,
        Carbon $receivedAt,
        ?Carbon $occurredAt,
        array $scrubbedPayload,
        ?string $message,
    ): array {
        [$task, $occurrence, $notificationReason] = DB::transaction(function () use ($project, $sender, $signature, $receivedAt, $occurredAt, $scrubbedPayload, $message) {
            $task = Task::query()
                ->where('project_id', $project->id)
                ->where('error_signature', $signature['signature'])
                ->lockForUpdate()
                ->first();

            $notificationReason = null;

            if (! $task instanceof Task) {
                $task = new Task([
                    'project_id' => $project->id,
                    'title' => $this->title($signature),
                    'description' => '',
                    'status' => TaskStatus::Pending->value,
                    'priority' => 'high',
                    'error_first_seen_at' => $receivedAt,
                    'error_occurrences_count' => 0,
                ]);
                $task->submitter()->associate($sender);
                $notificationReason = 'created';
            } elseif ($task->status === TaskStatus::Completed->value) {
                $task->status = TaskStatus::Pending->value;
                $notificationReason = 'reopened';
            }

            $occurrenceNumber = ((int) $task->error_occurrences_count) + 1;

            $task->fill([
                'title' => $this->title($signature),
                'error_signature' => $signature['signature'],
                'error_source' => $signature['source'],
                'error_environment' => $signature['environment'],
                'error_release' => $signature['release'],
                'error_git_sha' => $signature['git_sha'],
                'error_exception_class' => $signature['exception_class'],
                'error_name' => $signature['error_name'],
                'error_culprit_file' => $signature['culprit_file'],
                'error_culprit_line' => $signature['culprit_line'],
                'error_culprit_function' => $signature['culprit_function'],
                'error_occurrences_count' => $occurrenceNumber,
                'error_last_seen_at' => $receivedAt,
            ]);
            $task->save();

            $task->metadata()->updateOrCreate(
                ['task_id' => $task->id],
                [
                    'environment' => $signature['environment'] ?: 'production',
                    'url' => $this->requestUrl($scrubbedPayload) ?: $this->userUrl($scrubbedPayload) ?: (string) config('app.url'),
                    'source' => 'app_error',
                    'intake_type' => 'error',
                ],
            );

            $occurrence = $task->errorOccurrences()->create([
                'number' => $occurrenceNumber,
                'source' => $signature['source'],
                'environment' => $signature['environment'],
                'release' => $signature['release'],
                'git_sha' => $signature['git_sha'],
                'exception_class' => $signature['exception_class'],
                'error_name' => $signature['error_name'],
                'message' => $message,
                'culprit_file' => $signature['culprit_file'],
                'culprit_line' => $signature['culprit_line'],
                'culprit_function' => $signature['culprit_function'],
                'request_method' => $this->requestValue($scrubbedPayload, 'method'),
                'request_url' => $this->requestUrl($scrubbedPayload),
                'request_path' => $this->requestValue($scrubbedPayload, 'path'),
                'request_referrer' => $this->requestValue($scrubbedPayload, 'referrer') ?? $this->requestValue($scrubbedPayload, 'referer'),
                'occurred_at' => $occurredAt,
                'received_at' => $receivedAt,
                'payload' => $scrubbedPayload,
                'stacktrace' => $this->arrayValue(Arr::get($scrubbedPayload, 'stacktrace')),
                'context' => $this->arrayValue(Arr::get($scrubbedPayload, 'context')),
                'user' => $this->arrayValue(Arr::get($scrubbedPayload, 'user')),
                'metadata' => $this->arrayValue(Arr::get($scrubbedPayload, 'metadata')),
            ]);

            return [$task->refresh(), $occurrence->refresh(), $notificationReason];
        });

        if ($notificationReason !== null) {
            $this->notifyConfiguredRecipients($task, $notificationReason);
        }

        return [$task, $occurrence];
    }

    private function notifyConfiguredRecipients(Task $task, string $reason): void
    {
        $task->loadMissing('project');

        if (! $task->project instanceof Project) {
            return;
        }

        $recipients = $this->notifications->recipients($task->project);

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, new AppErrorReportedNotification($task, $reason));
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('Failed to dispatch app error notification.', [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'reason' => $reason,
            ]);
        }
    }

    private function title(array $signature): string
    {
        $name = $signature['exception_class'] ?? $signature['error_name'] ?? 'Error';
        $name = class_basename($name);
        $culprit = $this->culpritLabel($signature);
        $title = "{$name}".($culprit ? " at {$culprit}" : '');

        if ($signature['source'] === 'ui') {
            $title = "UI error: {$title}";
        }

        return Str::limit($title, 255, '');
    }

    private function culpritLabel(array $signature): ?string
    {
        $file = $signature['culprit_file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        $line = $signature['culprit_line'] ?? null;

        return $file.($line ? ":{$line}" : '');
    }

    private function message(array $payload): ?string
    {
        return $this->scrubber->scrubString(
            Arr::get($payload, 'exception.message')
                ?? Arr::get($payload, 'error.message')
                ?? Arr::get($payload, 'message')
        );
    }

    private function occurredAt(array $payload): ?Carbon
    {
        $occurredAt = $payload['occurred_at'] ?? null;

        return is_string($occurredAt) && $occurredAt !== '' ? Carbon::parse($occurredAt) : null;
    }

    private function requestUrl(array $payload): ?string
    {
        return $this->nullableString(Arr::get($payload, 'context.request.url') ?? Arr::get($payload, 'metadata.url'));
    }

    private function requestValue(array $payload, string $key): ?string
    {
        return $this->nullableString(Arr::get($payload, "context.request.{$key}"));
    }

    private function userUrl(array $payload): ?string
    {
        return $this->nullableString(Arr::get($payload, 'user.url'));
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }
}
