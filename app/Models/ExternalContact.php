<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalContact extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function externalUsers(): HasMany
    {
        return $this->hasMany(ExternalUser::class);
    }
}
