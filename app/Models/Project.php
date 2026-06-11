<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'external_widget_enabled' => 'boolean',
        'external_widget_guest_submissions_enabled' => 'boolean',
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
    public function externalUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExternalUser::class);
    }

    public function isManagedByUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $managedByOrganisationAdmin = false;
        if ($this->client?->organisation) {
            $managedByOrganisationAdmin = $this->client->organisation->organisationUsers()
                ->where('user_id', $userId)
                ->where('role', OrganisationRole::Administrator->value)
                ->exists();
        }

        if (! $managedByOrganisationAdmin && $this->organisation) {
            $managedByOrganisationAdmin = $this->organisation->organisationUsers()
                ->where('user_id', $userId)
                ->where('role', OrganisationRole::Administrator->value)
                ->exists();
        }

        return $this->client?->organisation?->author_id === $userId
            || $this->organisation?->author_id === $userId
            || $this->author_id === $userId
            || $managedByOrganisationAdmin;
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
