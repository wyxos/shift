<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use App\Services\AttachmentCleanup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn (Project $project) => app(AttachmentCleanup::class)->forProject($project));
    }

    protected $casts = [
        'external_widget_enabled' => 'boolean',
        'external_widget_guest_submissions_enabled' => 'boolean',
        'mcp_enabled' => 'boolean',
        'app_error_recurrence_enabled' => 'boolean',
        'app_error_quiet_hours' => 'integer',
        'app_error_daily_summary_enabled' => 'boolean',
    ];

    /**
     * Generate a new API token for the project.
     */
    public function generateApiToken(): string
    {
        $token = \Illuminate\Support\Str::random(60);
        $this->update(['token' => $token]);

        return $token;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function projectUser(): HasMany
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(ProjectEnvironment::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function appErrorNotificationUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_app_error_notification_users')
            ->withTimestamps();
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function accessOrganisation(): ?Organisation
    {
        return $this->organisation ?: $this->client?->organisation;
    }

    /**
     * Get the external users associated with this project.
     */
    public function externalIdentities(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function isManagedByUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        if ($this->author_id === $userId) {
            return true;
        }

        foreach ([$this->organisation, $this->client?->organisation] as $organisation) {
            if (! $organisation) {
                continue;
            }

            if ($organisation->author_id === $userId) {
                return true;
            }

            $membership = $organisation->relationLoaded('organisationUsers')
                ? $organisation->organisationUsers->firstWhere('user_id', $userId)
                : $organisation->organisationUsers()->where('user_id', $userId)->first();

            if ($membership?->role === OrganisationRole::Administrator) {
                return true;
            }
        }

        return false;
    }

    public function isVisibleToUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return static::query()
            ->whereKey($this->id)
            ->visibleTo($userId)
            ->exists();
    }

    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $projectQuery) use ($userId) {
            $projectQuery
                ->whereHas('client.organisation', function (Builder $organisationQuery) use ($userId) {
                    $organisationQuery
                        ->where('author_id', $userId)
                        ->orWhereHas('organisationUsers', function (Builder $memberQuery) use ($userId) {
                            $memberQuery
                                ->where('user_id', $userId)
                                ->where('role', OrganisationRole::Administrator->value);
                        });
                })
                ->orWhereHas('organisation', function (Builder $organisationQuery) use ($userId) {
                    $organisationQuery
                        ->where('author_id', $userId)
                        ->orWhereHas('organisationUsers', function (Builder $memberQuery) use ($userId) {
                            $memberQuery
                                ->where('user_id', $userId)
                                ->where('role', OrganisationRole::Administrator->value);
                        });
                })
                ->orWhere('author_id', $userId)
                ->orWhereHas('projectUser', function (Builder $projectUserQuery) use ($userId) {
                    $projectUserQuery->where('user_id', $userId);
                });
        });
    }
}
