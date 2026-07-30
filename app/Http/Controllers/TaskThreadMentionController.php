<?php

namespace App\Http\Controllers;

use App\Enums\TaskThreadAudience;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskThreadMentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskThreadMentionController extends Controller
{
    public function __construct(
        private readonly TaskThreadMentionService $mentions,
    ) {}

    public function index(Request $request, Task $task): JsonResponse
    {
        if (! Task::query()->visibleTo($request->user()?->id)->whereKey($task->id)->exists()) {
            abort(404);
        }

        $attributes = $request->validate([
            'audience' => ['required', Rule::enum(TaskThreadAudience::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $audience = TaskThreadAudience::from($attributes['audience']);

        return response()->json([
            ...$this->mentions->candidates($task, $user, $audience, $attributes['search'] ?? null),
            'can_add_collaborators' => $task->project !== null
                && app(\App\Services\ShiftPermissionService::class)->canManageTaskCollaborators($task, $user->id),
        ]);
    }
}
