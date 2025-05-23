<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalUser extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Get the task that this external user is associated with.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
