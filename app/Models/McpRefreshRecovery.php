<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class McpRefreshRecovery extends Model
{
    use MassPrunable;

    protected $primaryKey = 'request_hash';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
}
