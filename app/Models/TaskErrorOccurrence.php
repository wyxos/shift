<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskErrorOccurrence extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'stacktrace' => 'array',
        'context' => 'array',
        'user' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
