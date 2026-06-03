<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    /** @use HasFactory<\Database\Factories\OrganisationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    // user
    public function author()
    {
        return $this->belongsTo(User::class);
    }

    // organisation users
    public function organisationUsers(): HasMany
    {
        return $this->hasMany(OrganisationUser::class);
    }

    public function isVisibleToUser(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->author_id === $userId
            || $this->organisationUsers()->where('user_id', $userId)->exists();
    }

    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $organisationQuery) use ($userId) {
            $organisationQuery
                ->where('author_id', $userId)
                ->orWhereHas('organisationUsers', function (Builder $query) use ($userId) {
                    $query->where('user_id', $userId);
                });
        });
    }

    // projects directly owned by the organisation
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
