<?php

namespace App\Services;

use App\Enums\TaskThreadAudience;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskThread;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TaskThreadAudienceService
{
    public function audience(TaskThread $thread): TaskThreadAudience
    {
        return TaskThreadAudience::fromStoredType((string) $thread->type);
    }

    public function isVisibleToExternalUsers(TaskThread $thread): bool
    {
        return $this->audience($thread) === TaskThreadAudience::All;
    }

    public function assertContentMayBeShared(
        Task $task,
        TaskThreadAudience $audience,
        string $content,
    ): void {
        if ($audience === TaskThreadAudience::Team || trim($content) === '') {
            return;
        }

        $references = $this->contentReferences($content);

        if ($references['threads']->isNotEmpty()) {
            $referencedThreads = TaskThread::query()
                ->whereIn('id', $references['threads'])
                ->get(['id', 'task_id', 'type'])
                ->keyBy('id');

            foreach ($references['threads'] as $threadId) {
                $thread = $referencedThreads->get($threadId);

                if (! $thread instanceof TaskThread
                    || (int) $thread->task_id !== (int) $task->id
                    || ! $this->isVisibleToExternalUsers($thread)) {
                    throw ValidationException::withMessages([
                        'content' => 'Remove Team reply references before sending this message to All.',
                    ]);
                }
            }
        }

        if ($references['attachments']->isEmpty()) {
            return;
        }

        $attachments = Attachment::query()
            ->with('attachable')
            ->whereIn('id', $references['attachments'])
            ->get()
            ->keyBy('id');

        foreach ($references['attachments'] as $attachmentId) {
            $attachment = $attachments->get($attachmentId);

            if (! $attachment instanceof Attachment) {
                throw ValidationException::withMessages([
                    'content' => 'Remove unavailable attachments before sending this message to All.',
                ]);
            }

            $attachable = $attachment->attachable;

            if ($attachable instanceof Task && (int) $attachable->id === (int) $task->id) {
                continue;
            }

            if ($attachable instanceof TaskThread
                && (int) $attachable->task_id === (int) $task->id
                && $this->isVisibleToExternalUsers($attachable)) {
                continue;
            }

            throw ValidationException::withMessages([
                'content' => 'Remove Team or unrelated attachments before sending this message to All.',
            ]);
        }
    }

    /**
     * @return array{threads: Collection<int, int>, attachments: Collection<int, int>}
     */
    private function contentReferences(string $content): array
    {
        $threadIds = collect();
        $attachmentIds = collect();
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="shift-thread-content-root">'.$content.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        $root = $loaded ? $document->documentElement : null;

        if ($root instanceof DOMElement && $root->getAttribute('id') === 'shift-thread-content-root') {
            foreach ($root->getElementsByTagName('*') as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                $replyTo = $element->getAttribute('data-reply-to');
                if (preg_match('/^\d+$/', $replyTo) === 1) {
                    $threadIds->push((int) $replyTo);
                }

                foreach (['href', 'src'] as $attribute) {
                    $value = $element->getAttribute($attribute);

                    if (preg_match('/#comment-(\d+)(?:\z|[^\d])/', $value, $matches) === 1) {
                        $threadIds->push((int) $matches[1]);
                    }

                    if (preg_match('~/(?:shift/api/)?attachments/(\d+)/download(?:\z|[/?#])~', $value, $matches) === 1) {
                        $attachmentIds->push((int) $matches[1]);
                    }
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return [
            'threads' => $threadIds->filter()->unique()->values(),
            'attachments' => $attachmentIds->filter()->unique()->values(),
        ];
    }
}
