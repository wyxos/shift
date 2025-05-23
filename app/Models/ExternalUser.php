<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalUser extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Get the tasks that this external user is associated with.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
