<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskErrorOccurrence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class TaskErrorOccurrenceController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        if (! Task::query()->visibleTo(auth()->id())->whereKey($task->id)->exists()) {
            abort(404);
        }

        $occurrences = $task->errorOccurrences()
            ->latest('received_at')
            ->latest('id')
            ->paginate(15);

        return response()->json([
            'occurrences' => $occurrences
                ->getCollection()
                ->map(fn (TaskErrorOccurrence $occurrence) => $this->serialize($occurrence))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $occurrences->currentPage(),
                'last_page' => $occurrences->lastPage(),
                'per_page' => $occurrences->perPage(),
                'total' => $occurrences->total(),
                'from' => $occurrences->firstItem(),
                'to' => $occurrences->lastItem(),
            ],
        ]);
    }

    private function serialize(TaskErrorOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'number' => $occurrence->number,
            'source' => $occurrence->source,
            'environment' => $occurrence->environment,
            'release' => $occurrence->release,
            'git_sha' => $occurrence->git_sha,
            'exception_class' => $occurrence->exception_class,
            'error_name' => $occurrence->error_name,
            'message' => $occurrence->message,
            'culprit' => [
                'file' => $occurrence->culprit_file,
                'line' => $occurrence->culprit_line,
                'function' => $occurrence->culprit_function,
            ],
            'request' => [
                'method' => $occurrence->request_method,
                'url' => $occurrence->request_url,
                'path' => $occurrence->request_path,
                'referrer' => $occurrence->request_referrer,
                'query' => $this->requestContextArray($occurrence, 'query'),
                'body' => $this->requestContextArray($occurrence, 'body'),
            ],
            'occurred_at' => $occurrence->occurred_at?->toIso8601String(),
            'received_at' => $occurrence->received_at?->toIso8601String(),
            'created_at' => $occurrence->created_at?->toIso8601String(),
            'payload' => $occurrence->payload ?? [],
            'stacktrace' => $occurrence->stacktrace ?? [],
            'context' => $occurrence->context ?? [],
            'user' => $occurrence->user ?? [],
            'metadata' => $occurrence->metadata ?? [],
        ];
    }

    private function requestContextArray(TaskErrorOccurrence $occurrence, string $key): array
    {
        $value = Arr::get($occurrence->context ?? [], "request.{$key}");

        return is_array($value) ? $value : [];
    }
}
