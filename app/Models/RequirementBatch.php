<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementBatch extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentity::class);
    }

    public function metadata(): HasMany
    {
        return $this->hasMany(TaskMetadata::class);
    }
}
