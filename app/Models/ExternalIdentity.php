<?php

namespace App\Models;

use App\Enums\ExternalIdentityRole;
use App\Enums\TaskCollaboratorKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

class ExternalIdentity extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'project_participant_id' => 'integer',
        'project_environment_id' => 'integer',
        'role' => ExternalIdentityRole::class,
    ];

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

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ProjectParticipant::class, 'project_participant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (ExternalIdentity $identity): void {
            foreach ([
                'project_participant_id' => ProjectParticipant::class,
                'project_environment_id' => ProjectEnvironment::class,
            ] as $column => $model) {
                if ($identity->{$column} !== null && ! $model::query()
                    ->where('project_id', $identity->project_id)->whereKey($identity->{$column})->exists()) {
                    throw ValidationException::withMessages([$column => 'The identity and its related record must belong to the same project.']);
                }
            }
        });
    }

    public function projectEnvironment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class);
    }

    /**
     * Get the tasks that this external user has access to.
     */
    public function accessibleTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_collaborators', 'external_identity_id', 'task_id')
            ->wherePivot('kind', TaskCollaboratorKind::External->value)
            ->withPivotValue('kind', TaskCollaboratorKind::External->value)
            ->withTimestamps();
    }
}
