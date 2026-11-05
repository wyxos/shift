<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskThreadEmailBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'payload' => 'array', 'recipient_snapshot' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalIdentity(): BelongsTo
    {
        return $this->belongsTo(ExternalIdentity::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaskThreadEmailItem::class, 'batch_id');
    }
}
