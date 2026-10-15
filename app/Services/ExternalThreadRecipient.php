<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\ExternalNotificationDelivery;
use App\Models\TaskThread;
use Illuminate\Support\Facades\DB;

class ExternalThreadRecipient
{
    /** @param array<string, mixed> $snapshot */
    public function identity(TaskThread $thread, array $snapshot): ?ExternalIdentity
    {
        $query = ExternalIdentity::query()->where('project_id', $thread->task?->project_id);
        if (isset($snapshot['external_identity_id'])) {
            return $query->whereKey($snapshot['external_identity_id'])->first();
        }

        if (! filled($snapshot['external_id'] ?? null) || ! filled($snapshot['url'] ?? null)) {
            return null;
        }

        $matches = $query->where('external_id', (string) $snapshot['external_id'])
            ->where('url', (string) $snapshot['url'])
            ->when(isset($snapshot['environment']), fn ($query) => $query->where('environment', $snapshot['environment']))
            ->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @param array<string, mixed> $snapshot */
    public function key(array $snapshot): string
    {
        if (isset($snapshot['external_identity_id'])) {
            return 'identity:'.$snapshot['external_identity_id'];
        }

        return 'source:'.hash('sha256', json_encode([
            $snapshot['external_id'] ?? $snapshot['email'] ?? null,
            $snapshot['url'] ?? null,
            $snapshot['environment'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $snapshot */
    public function delivery(TaskThread $thread, array $snapshot, ?ExternalIdentity $identity): ExternalNotificationDelivery
    {
        $key = $this->key($snapshot);
        $legacyKey = (string) ($snapshot['external_id'] ?? $snapshot['email'] ?? $snapshot['url'] ?? 'unknown');
        $unambiguous = $identity !== null && ExternalIdentity::query()
            ->where('project_id', $identity->project_id)->where('external_id', $identity->external_id)->count() === 1;

        return DB::transaction(function () use ($thread, $key, $legacyKey, $unambiguous): ExternalNotificationDelivery {
            $existing = ExternalNotificationDelivery::findForTaskThread($thread->id, $key);
            if ($existing) {
                return $existing;
            }

            if ($unambiguous) {
                $legacy = ExternalNotificationDelivery::query()
                    ->where('source_type', ExternalNotificationDelivery::SOURCE_TASK_THREAD)
                    ->where('source_key', hash('sha256', $thread->id.':'.$legacyKey))
                    ->lockForUpdate()->first();
                if ($legacy) {
                    $legacy->forceFill(['source_key' => hash('sha256', $thread->id.':'.$key)])->save();

                    return $legacy;
                }
            }

            return ExternalNotificationDelivery::forTaskThread($thread, $key, 'thread.update');
        });
    }

    /** @param array<string, mixed> $snapshot */
    public function findDelivery(int $threadId, array $snapshot): ?ExternalNotificationDelivery
    {
        $delivery = ExternalNotificationDelivery::findForTaskThread($threadId, $this->key($snapshot));
        if ($delivery || isset($snapshot['external_identity_id'])) {
            return $delivery;
        }

        if (filled($snapshot['external_id'] ?? null) && filled($snapshot['url'] ?? null)) {
            $ids = ExternalIdentity::query()->where('external_id', (string) $snapshot['external_id'])
                ->where('url', (string) $snapshot['url'])->pluck('id');
            foreach ($ids as $id) {
                $delivery = ExternalNotificationDelivery::findForTaskThread($threadId, 'identity:'.$id);
                if ($delivery) {
                    return $delivery;
                }
            }
        }

        return ExternalNotificationDelivery::findForTaskThread($threadId,
            (string) ($snapshot['external_id'] ?? $snapshot['email'] ?? $snapshot['url'] ?? 'unknown'));
    }
}
