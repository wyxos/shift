<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskMetadata extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'finalized_at' => 'datetime',
    ];

    /**
     * Get the task that this metadata belongs to.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function requirementBatch(): BelongsTo
    {
        return $this->belongsTo(RequirementBatch::class);
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
