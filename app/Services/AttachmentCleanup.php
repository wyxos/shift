<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AttachmentCleanup
{
    public function forTask(Task $task): void
    {
        $this->forTasks(Task::query()->whereKey($task->id)->select('tasks.id'));
    }

    public function forProject(Project $project): void
    {
        $this->forProjects(Project::query()->whereKey($project->id));
    }

    public function forClient(Client $client): void
    {
        $this->forProjects(Project::query()->where('client_id', $client->id));
    }

    public function forOrganisation(Organisation $organisation): void
    {
        $this->forOrganisations(Organisation::query()->whereKey($organisation->id)->select('organisations.id'));
    }

    public function forUser(User $user): void
    {
        $this->forOrganisations(Organisation::query()->where('author_id', $user->id)->select('organisations.id'));
    }

    private function forOrganisations(Builder $organisationIds): void
    {
        $clientIds = Client::query()->whereIn('organisation_id', $organisationIds)->select('clients.id');
        $this->forProjects(Project::query()->where(function (Builder $query) use ($organisationIds, $clientIds): void {
            $query->whereIn('organisation_id', $organisationIds)->orWhereIn('client_id', $clientIds);
        }));
    }

    private function forProjects(Builder $projects): void
    {
        $this->forTasks(Task::query()->whereIn('project_id', $projects->select('projects.id'))->select('tasks.id'));
    }

    private function forTasks(Builder $taskIds): void
    {
        $threadIds = TaskThread::withoutGlobalScope('published')->whereIn('task_id', $taskIds)->select('task_threads.id');

        $this->deleteRecords(Attachment::query()->where(function (Builder $query) use ($taskIds, $threadIds): void {
            $query->where(function (Builder $direct) use ($taskIds): void {
                $direct->where('attachable_type', (new Task)->getMorphClass())->whereIn('attachable_id', $taskIds);
            })->orWhere(function (Builder $threads) use ($threadIds): void {
                $threads->where('attachable_type', (new TaskThread)->getMorphClass())
                    ->whereIn('attachable_id', $threadIds);
            });
        }));
    }

    public function forThread(TaskThread $thread): void
    {
        $this->deleteRecords($thread->attachments()->getQuery());
    }

    public function file(Attachment $attachment): void
    {
        if (Storage::exists($attachment->path) && ! Storage::delete($attachment->path)) {
            throw new RuntimeException('Attachment file could not be removed.');
        }
    }

    private function deleteRecords(Builder $query): void
    {
        $query->chunkById(100, function (Collection $attachments): void {
            foreach ($attachments as $attachment) {
                $this->file($attachment);
            }

            Attachment::query()->whereKey($attachments->modelKeys())->delete();
        });
    }
}
