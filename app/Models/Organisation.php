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

    // projects directly owned by the organisation
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function scopeVisibleToUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId) {
            $query->where('author_id', $userId)
                ->orWhereHas('organisationUsers', function (Builder $query) use ($userId) {
                    $query->where('user_id', $userId);
                });
        });
    }
}
