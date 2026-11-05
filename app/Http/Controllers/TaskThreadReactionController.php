<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetTaskThreadReactionRequest;
use App\Models\Task;
use App\Models\TaskThreadReaction;
use Illuminate\Http\JsonResponse;

class TaskThreadReactionController extends Controller
{
    public function __invoke(SetTaskThreadReactionRequest $request, Task $task): JsonResponse
    {
        $actor = $request->actor;
        $thread = $request->thread;
        $attributes = [
            'task_thread_id' => $thread->id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'emoji_key' => array_search($request->validated('emoji'), TaskThreadReaction::EMOJI, true),
        ];

        if ($request->boolean('reacted')) {
            TaskThreadReaction::query()->insertOrIgnore($attributes);
        } else {
            TaskThreadReaction::query()->where($attributes)->delete();
        }

        return response()->json([
            'reactions' => $thread->reactionSummary($actor),
        ]);
    }
}
