<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'attachable_id',
        'attachable_type',
        'original_filename',
        'path',
    ];

    /**
     * Get the parent attachable model.
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
