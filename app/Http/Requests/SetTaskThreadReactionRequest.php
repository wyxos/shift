<?php

namespace App\Http\Requests;

use App\Models\ExternalIdentity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\TaskThreadReaction;
use App\Models\User;
use App\Services\ExternalActorResolver;
use App\Services\ExternalTaskAccess;
use App\Services\ShiftPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetTaskThreadReactionRequest extends FormRequest
{
    public User|ExternalIdentity $actor;

    public TaskThread $thread;

    public function authorize(ShiftPermissionService $permissions, ExternalActorResolver $actors, ExternalTaskAccess $access): bool
    {
        $task = $this->route('task');
        $user = $this->user();
        abort_unless($task instanceof Task && $user instanceof User, 404);

        if ($this->routeIs('api.task-threads.reaction')) {
            $project = Project::query()->visibleTo($user->id)
                ->where('token', $this->input('project'))
                ->whereKey($task->project_id)
                ->firstOrFail();
            $actor = $actors->fromRequest($project, $this)?->identity;
            abort_unless($actor && $access->canCommentOnProjectItem($task, $actor), 404);
            $this->actor = $actor;
            $this->thread = $task->threads()->ofType('external')->findOrFail($this->route('threadId'));
        } else {
            abort_unless($permissions->canCommentOnTask($task, $user->id), 404);
            $this->actor = $user;
            $this->thread = $task->threads()->findOrFail($this->route('thread'));
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(array_values(TaskThreadReaction::EMOJI))],
            'reacted' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['emoji.in' => 'Choose one of the available reactions.'];
    }
}
