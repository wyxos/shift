<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ExternalNotificationDelivery extends Model
{
    public const SOURCE_TASK_COLLABORATOR = 'task_collaborator_notification';

    public const SOURCE_TASK_THREAD = 'task_thread';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_status_code' => 'integer',
            'production' => 'boolean',
            'last_attempted_at' => 'datetime',
            'callback_delivered_at' => 'datetime',
            'fallback_dispatched_at' => 'datetime',
            'fallback_attempted_at' => 'datetime',
            'fallback_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function forTaskCollaborator(TaskCollaboratorNotification $notification, string $handler): self
    {
        return self::query()->firstOrCreate(
            [
                'source_type' => self::SOURCE_TASK_COLLABORATOR,
                'source_key' => self::sourceKey((string) $notification->id),
            ],
            [
                'delivery_id' => (string) Str::uuid(),
                'handler' => $handler,
                'task_collaborator_notification_id' => $notification->id,
            ],
        );
    }

    public static function forTaskThread(TaskThread $thread, string $recipientKey, string $handler): self
    {
        return self::query()->firstOrCreate(
            [
                'source_type' => self::SOURCE_TASK_THREAD,
                'source_key' => self::sourceKey($thread->id.':'.$recipientKey),
            ],
            [
                'delivery_id' => (string) Str::uuid(),
                'handler' => $handler,
                'task_thread_id' => $thread->id,
            ],
        );
    }

    public static function findForTaskCollaborator(int $notificationId): ?self
    {
        return self::query()
            ->where('source_type', self::SOURCE_TASK_COLLABORATOR)
            ->where('source_key', self::sourceKey((string) $notificationId))
            ->first();
    }

    public static function findForTaskThread(int $threadId, string $recipientKey): ?self
    {
        return self::query()
            ->where('source_type', self::SOURCE_TASK_THREAD)
            ->where('source_key', self::sourceKey($threadId.':'.$recipientKey))
            ->first();
    }

    public function taskCollaboratorNotification(): BelongsTo
    {
        return $this->belongsTo(TaskCollaboratorNotification::class);
    }

    public function taskThread(): BelongsTo
    {
        return $this->belongsTo(TaskThread::class);
    }

    public function recordAttempt(): void
    {
        $this->increment('attempts', 1, [
            'last_attempted_at' => now(),
        ]);

        $this->refresh();
    }

    public function recordAttemptFailure(string $failureType, ?int $statusCode): void
    {
        $this->forceFill([
            'last_failure_type' => $failureType,
            'last_status_code' => $statusCode,
        ])->save();
    }

    public function markCallbackDelivered(bool $production, int $statusCode): void
    {
        $this->forceFill([
            'production' => $production,
            'last_status_code' => $statusCode,
            'last_failure_type' => null,
            'callback_delivered_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    public function markFallbackDispatched(): void
    {
        $this->forceFill(['fallback_dispatched_at' => now()])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'completed_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    public function markFailed(string $failureType, ?int $statusCode = null): void
    {
        $this->forceFill([
            'last_failure_type' => $failureType,
            'last_status_code' => $statusCode,
            'failed_at' => now(),
        ])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill([
            'cancelled_at' => now(),
            'failed_at' => null,
        ])->save();
    }

    private static function sourceKey(string $value): string
    {
        return hash('sha256', $value);
    }
}
