<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProjectUser extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectUserFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tasks that this project user is associated with.
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'submitter');
    }
}
