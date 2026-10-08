<?php

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('searches collaborators without moving the task details and adds a matching team member', function () {
    config()->set('collaborator_search.enabled', false);
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create();
    $members = collect(range(1, 12))->map(function (int $number) use ($project): User {
        $user = User::factory()->create(['name' => 'Alexandra '.$number]);
        ProjectUser::factory()->create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'user_name' => $user->name, 'user_email' => $user->email, 'registration_status' => 'registered',
        ]);

        return $user;
    });
    $task = Task::factory()->for($project)->create(['title' => 'Review collaborator access', 'description' => 'Check collaborator search.']);
    $task->submitter()->associate($owner)->save();
    $this->actingAs($owner);

    $page = visit('/tasks?task='.$task->id)->resize(1440, 900)
        ->assertNoSmoke()
        ->assertVisible('[data-testid="task-collaborators-search"]')
        ->assertNotPresent('[data-testid="task-collaborators-dropdown"]')
        ->assertScript("(window.collaboratorFieldHeight = document.querySelector('[data-testid=\"task-collaborators\"]').getBoundingClientRect().height) > 0");
    $page->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alexandra 1')
        ->assertSee('SHIFT team')
        ->assertScript("document.querySelectorAll('[data-testid*=\"-collaborator-option-\"]').length === 10")
        ->assertScript("document.querySelector('[data-testid=\"task-collaborators\"]').getBoundingClientRect().height === window.collaboratorFieldHeight")
        ->click('[data-testid="internal-collaborator-option-'.$members->first()->id.'"]')
        ->waitForText('Task changes saved')
        ->assertPresent('[aria-label="Remove Alexandra 1"]')
        ->assertNotPresent('[data-testid="task-collaborators-dropdown"]')
        ->assertNoSmoke();
    $this->assertDatabaseHas('task_collaborators', ['task_id' => $task->id, 'user_id' => $members->first()->id]);

    $page->resize(390, 844)
        ->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alexandra 10')
        ->assertScript("document.querySelector('[data-testid=\"task-collaborators-dropdown\"]').getBoundingClientRect().right <= window.innerWidth")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoSmoke();
});

it('adds collaborators from two registered environments while the task environment remains N/A', function () {
    config()->set('collaborator_search.enabled', false);
    Queue::fake();
    $owner = User::factory()->create();
    $project = Project::factory()->withAuthor($owner->id)->create(['token' => 'project-token']);
    $responses = [];
    foreach (['staging', 'production'] as $environment) {
        $url = "https://{$environment}-client.test";
        $project->environments()->create(['environment' => $environment, 'url' => $url, 'callback_trusted_at' => now()]);
        $responses[$url.'/shift/api/collaborators/external*'] = Http::response([
            'environment' => $environment,
            'url' => $url,
            'users' => [['id' => '7', 'name' => 'Alex Client', 'email' => 'alex@example.test']],
        ]);
    }
    Http::fake($responses);
    $task = Task::factory()->for($project)->create(['title' => 'Review general project work']);
    $task->submitter()->associate($owner)->save();
    $this->actingAs($owner);

    $stagingKey = rawurlencode(json_encode(['staging', '7']));
    $productionKey = rawurlencode(json_encode(['production', '7']));
    $page = visit('/tasks?task='.$task->id)->resize(1440, 900)
        ->assertNoSmoke()
        ->assertSee('N/A')
        ->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alex Client')
        ->assertPresent('[data-testid="external-collaborator-option-'.$stagingKey.'"]')
        ->assertPresent('[data-testid="external-collaborator-option-'.$productionKey.'"]')
        ->click('[data-testid="external-collaborator-option-'.$stagingKey.'"]')
        ->waitForText('Task changes saved')
        ->fill('[data-testid="task-collaborators-search"]', 'Alex')
        ->waitForText('Alex Client')
        ->click('[data-testid="external-collaborator-option-'.$productionKey.'"]')
        ->assertPresent('[aria-label="Remove Alex Client from Staging"]')
        ->assertPresent('[aria-label="Remove Alex Client from Production"]')
        ->assertSee('N/A');

    expect($page->script(<<<JS
        async () => {
            const path = '/tasks/{$task->id}/collaborators';
            const completedSaves = () => performance.getEntriesByType('resource')
                .filter((entry) => new URL(entry.name).pathname === path).length;
            if (completedSaves() >= 2) return true;
            return await new Promise((resolve) => {
                const observer = new PerformanceObserver(() => {
                    if (completedSaves() < 2) return;
                    observer.disconnect();
                    resolve(true);
                });
                observer.observe({ type: 'resource', buffered: true });
            });
        }
    JS))->toBeTrue();
    $page->screenshot(false, 'task-collaborator-na-desktop.png');

    expect($task->fresh()->metadata)->toBeNull();
    expect($task->externalCollaborators()->count())->toBe(2);

    $page->resize(390, 844)
        ->assertPresent('[aria-label="Remove Alex Client from Staging"]')
        ->assertPresent('[aria-label="Remove Alex Client from Production"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(false, 'task-collaborator-na-mobile.png')
        ->assertNoSmoke();
});
