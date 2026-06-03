<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEnvironment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'external_widget_enabled' => 'boolean',
        'external_widget_guest_submissions_enabled' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
