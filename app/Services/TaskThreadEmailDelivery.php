<?php

namespace App\Services;

use App\Jobs\SendExternalNotificationFallbackEmail;
use App\Models\ExternalIdentity;
use App\Models\ExternalNotificationDelivery;
use App\Models\TaskThreadEmailBatch;
use App\Notifications\TaskThreadEmailDigest;
use Illuminate\Support\Str;

class TaskThreadEmailDelivery
{
    public function prepare(TaskThreadEmailBatch $batch): void
    {
        ExternalNotificationDelivery::query()->firstOrCreate([
            'source_type' => 'task_thread_email_batch', 'source_key' => hash('sha256', (string) $batch->id),
        ], [
            'task_thread_email_batch_id' => $batch->id, 'delivery_id' => (string) Str::uuid(), 'handler' => 'thread.update',
        ]);
    }

    public function send(TaskThreadEmailBatch $batch, ExternalIdentity $recipient): void
    {
        $delivery = ExternalNotificationDelivery::query()->where('task_thread_email_batch_id', $batch->id)->firstOrFail();
        $data = $batch->payload;
        $snapshot = $data['_recipient'];
        $source = $data['_source'];
        unset($data['_recipient'], $data['_source']);
        if ($recipient->url !== $snapshot['url'] || (string) $recipient->external_id !== (string) $snapshot['external_id']) {
            $delivery->markCancelled();

            return;
        }

        if (! $delivery->callback_delivered_at) {
            $delivery->recordAttempt();
            $response = app(ExternalNotificationService::class)->sendNotification(
                $batch->task->project, $snapshot['url'], 'thread.update', $data, $source, $delivery->delivery_id,
            );
            $delivery->markCallbackDelivered((bool) $response->json('production'), $response->status());
        }

        if ($delivery->production === false && filled($snapshot['email'])) {
            if (! $delivery->fallback_dispatched_at) {
                SendExternalNotificationFallbackEmail::dispatch(
                    $delivery->id, $snapshot['email'], new TaskThreadEmailDigest($data),
                )->afterCommit();
                $delivery->markFallbackDispatched();
            }

            return;
        }

        $delivery->markCompleted();
    }
}
