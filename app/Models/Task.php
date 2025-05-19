<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function projectUser(): BelongsTo
    {
        return $this->belongsTo(ProjectUser::class);
    }

    public function externalTaskSource(): HasOne
    {
        return $this->hasOne(ExternalTaskSource::class);
    }

    /**
     * Check if the task was submitted by an external user.
     *
     * @return bool
     */
    public function isExternallySubmitted(): bool
    {
        return $this->externalTaskSource()->exists();
    }
}
