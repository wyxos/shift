<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskThread extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'is_draft' => false,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('published', fn (Builder $query) => $query->where('task_threads.is_draft', false));
    }

    public function scopeWithDraftsFor(Builder $query, User $user): Builder
    {
        return $query->withoutGlobalScope('published')
            ->where(fn (Builder $query) => $query
                ->where('task_threads.is_draft', false)
                ->orWhere(fn (Builder $query) => $query
                    ->where('task_threads.sender_type', User::class)
                    ->where('task_threads.sender_id', $user->id)));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = auth()->user();

        return $user instanceof User ? $query->withDraftsFor($user) : $query;
    }

    /**
     * Scope a query to only include threads of a specific type.
     */
    public function scopeOfType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_draft' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the task that owns the thread.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the sender of the thread message.
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attachments for the thread.
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(TaskThreadMention::class);
    }
}
