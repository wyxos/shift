<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Generate a new API token for the project.
     *
     * @return string
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Get the external users associated with this project.
     */
    public function externalUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ExternalUser::class);
    }
}
