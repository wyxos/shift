<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::deleting(function ($task) {
            // Delete all attachments associated with this task
            foreach ($task->attachments as $attachment) {
                // Delete the file from storage if it exists
                if (\Illuminate\Support\Facades\Storage::exists($attachment->path)) {
                    \Illuminate\Support\Facades\Storage::delete($attachment->path);
                }

                // Delete the attachment record
                $attachment->delete();
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the submitter of the task (polymorphic relationship).
     * This can be either a User or an ExternalUser.
     */
    public function submitter(): MorphTo
    {
        return $this->morphTo();
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(TaskMetadata::class);
    }

    /**
     * Get the project user associated with the task.
     * This defines which user on Shift is being given access to a project.
     */
    public function projectUser(): BelongsTo
    {
        return $this->belongsTo(ProjectUser::class);
    }

    /**
     * Check if the task was submitted by an external user.
     *
     * @return bool
     */
    public function isExternallySubmitted(): bool
    {
        return $this->submitter instanceof ExternalUser;
    }

    /**
     * Get the attachments for the task.
     */
    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get the threads for the task.
     */
    public function threads(): HasMany
    {
        return $this->hasMany(TaskThread::class);
    }
}
