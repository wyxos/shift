<?php

namespace App\Models;

use App\Enums\TaskCollaboratorKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskThreadMention extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => TaskCollaboratorKind::class,
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskThread::class, 'task_thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalUser(): BelongsTo
    {
        return $this->belongsTo(ExternalUser::class);
    }
}
