<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppErrorSummary extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'scheduled_for' => 'immutable_datetime',
        'dispatch_requested_at' => 'immutable_datetime',
        'dispatched_at' => 'immutable_datetime',
        'delivery_started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
