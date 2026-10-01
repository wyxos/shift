<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\TaskCollaboratorService;
use Illuminate\Support\Facades\Http;

it('uses real meilisearch ranking and updates registered collaborator membership', function () {
    config()->set('collaborator_search.enabled', true);
    config()->set('collaborator_search.index_prefix', 'shift_collaborators_integration_'.getmypid());
    $owner = User::factory()->create(['name' => 'Owner']);
    $project = Project::factory()->withAuthor($owner->id)->create();
    $members = collect(range(1, 15))->map(function (int $number) use ($project): User {
        $user = User::factory()->create(['name' => 'Alexandra '.$number]);
        ProjectUser::factory()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'user_name' => $user->name, 'user_email' => $user->email, 'registration_status' => 'registered',
        ]);

        return $user;
    });
    $index = config('collaborator_search.index_prefix').'_project_'.$project->id;

    try {
        $matches = app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Alexndra');
        expect($matches)->toHaveCount(10);
        expect($matches->pluck('id')->diff($members->pluck('id')))->toHaveCount(0);
        $renamed = $members->first();
        $renamed->update(['name' => 'Beatrice Unique']);
        $matches = app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Beatrce');
        expect($matches->pluck('id')->all())->toBe([$renamed->id]);
        expect($matches->first()->name)->toBe('Beatrice Unique');
        $project->projectUser()->where('user_id', $renamed->id)->delete();
        expect(app(TaskCollaboratorService::class)->searchInternalCandidates($project, 'Beatrce'))->toHaveCount(0);
    } finally {
        Http::baseUrl(config('collaborator_search.host'))
            ->withToken((string) config('collaborator_search.key'))
            ->delete('/indexes/'.$index);
    }
})->skip(getenv('RUN_MEILISEARCH_INTEGRATION') !== '1', 'Set RUN_MEILISEARCH_INTEGRATION=1 to use a local Meilisearch service.');
