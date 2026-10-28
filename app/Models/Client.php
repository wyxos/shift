<?php

namespace App\Models;

use App\Services\AttachmentCleanup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn (Client $client) => app(AttachmentCleanup::class)->forClient($client));
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }
}
