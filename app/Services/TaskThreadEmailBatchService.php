<?php

namespace App\Services;

use App\Exceptions\ExternalNotificationException;
use App\Jobs\SendTaskThreadEmailBatch;
use App\Models\ExternalIdentity;
use App\Models\ExternalNotificationDelivery;
use App\Models\TaskThread;
use App\Models\TaskThreadEmailBatch;
use App\Models\TaskThreadEmailItem;
use App\Models\User;
use App\Notifications\TaskThreadEmailDigest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class TaskThreadEmailBatchService
{
    private const QUIET_SECONDS = 120;

    public function schedule(TaskThread $thread, User|ExternalIdentity $recipient): void
    {
        if ($thread->is_draft) {
            return;
        }

        $snapshot = $recipient instanceof ExternalIdentity
            ? ['url' => $recipient->url, 'external_id' => $recipient->external_id, 'email' => $recipient->email, 'environment' => $recipient->environment]
            : null;
        $key = hash('sha256', $thread->task_id.':'.$recipient->getMorphClass().':'.$recipient->getKey().':'.json_encode($snapshot));
        DB::transaction(function () use ($thread, $recipient, $key, $snapshot): void {
            $due = $thread->created_at->copy()->addSeconds(self::QUIET_SECONDS);
            $attributes = [
                'active_key' => $key,
                'task_id' => $thread->task_id,
                'user_id' => $recipient instanceof User ? $recipient->id : null,
                'external_identity_id' => $recipient instanceof ExternalIdentity ? $recipient->id : null,
                'recipient_snapshot' => $snapshot,
                'scheduled_at' => $due,
            ];
            $batch = TaskThreadEmailBatch::query()->where('active_key', $key)->lockForUpdate()->first();
            if (! $batch) {
                try {
                    $batch = DB::transaction(fn () => TaskThreadEmailBatch::query()->create($attributes));
                } catch (UniqueConstraintViolationException) {
                    $batch = TaskThreadEmailBatch::query()->where('active_key', $key)->lockForUpdate()->firstOrFail();
                }
            }

            $inserted = TaskThreadEmailItem::query()->insertOrIgnore([
                'batch_id' => $batch->id, 'thread_id' => $thread->id, 'recipient_key' => $key,
            ]);
            if (! $inserted) {
                return;
            }

            if ($due->greaterThan($batch->scheduled_at)) {
                $batch->scheduled_at = $due;
                $batch->save();
            }

            $connection = (string) config('queue.default');
            if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'null'], true)) {
                // Quiet-period delivery needs a durable delayed job, including in local sync environments.
                $connection = 'database';
            }
            $job = (new SendTaskThreadEmailBatch($batch->id))->onConnection($connection)->delay($batch->scheduled_at);
            $queue = config("queue.connections.{$connection}", []);
            if (($queue['driver'] ?? null) === 'database'
                && ($queue['connection'] ?? config('database.default')) === $batch->getConnection()->getName()) {
                Bus::dispatch($job->beforeCommit());
            } else {
                Bus::dispatch($job->afterCommit());
            }
        }, attempts: 3);
    }

    public function send(int $batchId): void
    {
        $this->prepare($batchId);
        try {
            DB::transaction(function () use ($batchId): void {
                $batch = TaskThreadEmailBatch::query()->lockForUpdate()->find($batchId);
                if (! $batch || $batch->sent_at || $batch->payload === null) {
                    return;
                }

                $batch->load(['task.project', 'user', 'externalIdentity']);
                $recipient = $this->recipient($batch);
                if ($recipient && $this->rebuildChangedBatch($batch, $recipient)) {
                    return;
                }
                if ($recipient) {
                    if ($recipient instanceof User) {
                        Notification::sendNow($recipient, new TaskThreadEmailDigest($batch->payload), ['mail']);
                    } else {
                        app(TaskThreadEmailDelivery::class)->send($batch, $recipient);
                    }
                } else {
                    ExternalNotificationDelivery::query()->where('task_thread_email_batch_id', $batch->id)
                        ->first()?->markCancelled();
                }
                $batch->forceFill(['sent_at' => now()])->save();
            }, attempts: 3);
        } catch (Throwable $error) {
            $delivery = ExternalNotificationDelivery::query()->where('task_thread_email_batch_id', $batchId)->first();
            if ($delivery) {
                $delivery->recordAttempt();
                $delivery->recordAttemptFailure(
                    $error instanceof ExternalNotificationException ? $error->failureType : 'email_batch',
                    $error instanceof ExternalNotificationException ? $error->statusCode : null,
                );
            }
            throw $error;
        }
    }

    private function rebuildChangedBatch(TaskThreadEmailBatch $batch, User|ExternalIdentity $recipient): bool
    {
        $messages = collect($batch->payload['messages'])->keyBy('thread_id');
        $threads = TaskThread::query()->where('task_id', $batch->task_id)->whereIn('id', $messages->keys())
            ->when($recipient instanceof ExternalIdentity, fn ($query) => $query->ofType('external'))->get();
        $unchanged = $threads->count() === $messages->count() && $threads->every(
            fn (TaskThread $thread) => $thread->content === $messages[$thread->id]['content'],
        );
        if ($unchanged) {
            return false;
        }

        $delivery = ExternalNotificationDelivery::query()->where('task_thread_email_batch_id', $batch->id)->first();
        $alreadyDelivered = $delivery?->callback_delivered_at !== null;
        $delivery?->markCancelled();
        $batch->forceFill(['sent_at' => now()])->save();
        if (! $alreadyDelivered) {
            // Changed or removed comments invalidate this immutable delivery. Valid comments join a fresh batch.
            $batch->items()->whereIn('thread_id', $threads->modelKeys())->delete();
            foreach ($threads as $thread) {
                $this->schedule($thread, $recipient);
            }
        }

        return true;
    }

    private function prepare(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = TaskThreadEmailBatch::query()->lockForUpdate()->find($batchId);
            if (! $batch || $batch->sent_at || $batch->payload !== null || $batch->scheduled_at->isFuture()) {
                return;
            }

            $batch->load(['task.project', 'user', 'externalIdentity']);
            $recipient = $this->recipient($batch);
            $messages = $recipient
                ? TaskThread::query()->where('task_id', $batch->task_id)
                    ->whereIn('id', $batch->items()->select('thread_id'))
                    ->when($recipient instanceof ExternalIdentity, fn ($query) => $query->ofType('external'))
                    ->where(fn ($query) => $query->whereNull('sender_type')->orWhereNull('sender_id')
                        ->orWhere('sender_type', '!=', $recipient->getMorphClass())
                        ->orWhere('sender_id', '!=', $recipient->getKey()))
                    ->orderBy('created_at')->orderBy('id')->get()
                    ->map(fn (TaskThread $thread) => [
                        'thread_id' => $thread->id, 'author' => $thread->sender_name,
                        'content' => $thread->content, 'audience' => $thread->type === 'internal' ? 'team' : 'all',
                    ])->all()
                : [];
            $batch->active_key = null;
            if ($messages === []) {
                $batch->sent_at = now();
            } else {
                $last = $messages[array_key_last($messages)];
                $batch->payload = [
                    'type' => 'task_thread', 'task_id' => $batch->task_id, 'task_title' => $batch->task->title,
                    'thread_id' => $last['thread_id'], 'messages' => $messages,
                    'content' => implode("\n\n", array_column($messages, 'content')),
                    'url' => $recipient instanceof User
                        ? route('tasks.index', ['task' => $batch->task_id])
                        : rtrim($recipient->url, '/').'/shift/tasks?task='.$batch->task_id,
                ];
                if ($recipient instanceof ExternalIdentity) {
                    $payload = $batch->payload;
                    $payload['user_id'] = $recipient->external_id;
                    $payload['_recipient'] = $batch->recipient_snapshot;
                    $payload['_source'] = ['url' => config('app.url'), 'environment' => app()->environment()];
                    $batch->payload = $payload;
                    app(TaskThreadEmailDelivery::class)->prepare($batch);
                }
            }
            // Persist the sealed payload before network I/O. Retries reuse its delivery ID and body.
            $batch->save();
        });
    }

    private function recipient(TaskThreadEmailBatch $batch): User|ExternalIdentity|null
    {
        if (! $batch->task) {
            return null;
        }
        if ($batch->user) {
            return $batch->task->internalCollaborators()->whereKey($batch->user_id)->exists() ? $batch->user : null;
        }

        $identity = $batch->externalIdentity;

        if (! $identity) {
            return null;
        }
        foreach ($batch->recipient_snapshot as $field => $value) {
            if ($identity->getAttribute($field) !== $value) {
                return null;
            }
        }

        return $batch->task->externalCollaborators()->whereKey($identity->id)->exists() ? $identity : null;
    }
}
