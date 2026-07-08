<?php

namespace App\Models;

use App\Enums\TaskCollaboratorKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCollaboratorNotification extends Model
{
    public const EVENT_TASK_CREATED = 'task_created';

    public const EVENT_COLLABORATOR_ADDED = 'collaborator_added';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => TaskCollaboratorKind::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalUser(): BelongsTo
    {
        return $this->belongsTo(ExternalUser::class);
    }

    public function markSent(): void
    {
        $this->forceFill(['sent_at' => now()])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill(['cancelled_at' => now()])->save();
    }
}
