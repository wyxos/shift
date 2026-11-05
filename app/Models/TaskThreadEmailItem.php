<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskThreadEmailItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskThread::class, 'thread_id');
    }
}
