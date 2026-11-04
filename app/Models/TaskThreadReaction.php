<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaskThreadReaction extends Model
{
    public const EMOJI = [
        'thumbs_up' => '👍',
        'check_mark' => '✅',
        'clipboard' => '📋',
        'laughing' => '🤣',
        'heart' => '❤️',
    ];

    public $timestamps = false;

    public function scopeSummaryFor(Builder $query, User|ExternalIdentity $actor): Builder
    {
        return $query->select('task_thread_id', 'emoji_key')
            ->selectRaw('COUNT(*) AS reaction_count')
            ->selectRaw('MAX(CASE WHEN actor_type = ? AND actor_id = ? THEN 1 ELSE 0 END) AS reacted', [$actor->getMorphClass(), $actor->getKey()])
            ->groupBy('task_thread_id', 'emoji_key');
    }

    /** @return array{emoji: string, count: int, reacted: bool} */
    public function summary(): array
    {
        return [
            'emoji' => self::EMOJI[$this->emoji_key],
            'count' => (int) $this->getAttribute('reaction_count'),
            'reacted' => (bool) $this->getAttribute('reacted'),
        ];
    }
}
