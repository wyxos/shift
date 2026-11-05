<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Collection<int, TaskThreadReaction>  $reactions
     * @return array<int, array{emoji: string, count: int, reacted: bool, reactors: list<string>}>
     */
    public static function summarize(Collection $reactions, User|ExternalIdentity $actor): array
    {
        return $reactions->groupBy('emoji_key')->sortKeys()
            ->map(fn ($group, string $key) => [
                'emoji' => self::EMOJI[$key],
                'count' => $group->count(),
                'reacted' => $group->contains(fn (TaskThreadReaction $reaction) => $reaction->actor_type === $actor->getMorphClass()
                    && (string) $reaction->actor_id === (string) $actor->getKey()),
                'reactors' => $group->map(fn (TaskThreadReaction $reaction) => trim((string) $reaction->actor?->name) ?: 'Unknown user')
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ])
            ->values()->all();
    }
}
