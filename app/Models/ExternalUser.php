<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ExternalUser extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Get the tasks that this external user is associated with.
     */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'submitter');
    }

    /**
     * Get the project that this external user is associated with.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the tasks that this external user has access to.
     */
    public function accessibleTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'external_access');
    }
}
