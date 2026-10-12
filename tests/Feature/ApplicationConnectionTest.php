<?php

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function connectionEnvironment(array $attributes = []): ProjectEnvironment
{
    return ProjectEnvironment::create([
        'project_id' => $attributes['project_id'] ?? Project::factory()->create()->id,
        'environment' => 'local',
        'url' => 'https://app.test',
        'external_widget_enabled' => false,
        'external_widget_guest_submissions_enabled' => false,
        ...$attributes,
    ]);
}

test('connection guidance is available before creating the first project', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/connect')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Connection')->has('projects', 0)->has('environments', 0)->where('selectedProjectId', null));
});

test('connection status counts received feedback and errors only for the selected application', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id, 'client_id' => null]);
    $environment = connectionEnvironment([
        'project_id' => $project->id, 'environment' => 'local', 'url' => 'https://app.test', 'external_widget_enabled' => false,
    ]);
    $feedback = Task::factory()->create(['project_id' => $project->id]);
    $feedback->metadata()->create(['environment' => 'local', 'url' => 'https://app.test/orders', 'source' => 'embedded_widget']);
    $error = Task::factory()->create(['project_id' => $project->id, 'error_signature' => 'sample']);
    $error->metadata()->create(['environment' => 'local', 'url' => 'https://app.test']);
    $unrelated = Task::factory()->create(['project_id' => $project->id, 'error_signature' => 'other']);
    $unrelated->metadata()->create(['environment' => 'local', 'url' => 'https://other-app.test']);
    $apiTask = Task::factory()->create(['project_id' => $project->id]);
    $apiTask->metadata()->create(['environment' => 'local', 'url' => 'https://app.test']);

    $this->actingAs($user)->get('/connect?project_id='.$project->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Connection')->where('selectedProjectId', $project->id)
        ->where('environments.0.id', $environment->id)->where('environments.0.feedback_enabled', false)
        ->where('environments.0.feedback_report.id', $feedback->id)
        ->where('environments.0.error_report.id', $error->id)
        ->where('environments.0.feedback_report.url', route('tasks.index', ['task' => $feedback->id])));
});

test('connection report matching treats URL wildcard characters literally', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id, 'client_id' => null]);
    connectionEnvironment(['project_id' => $project->id, 'environment' => 'local', 'url' => 'https://app.test/base_one']);
    $other = Task::factory()->create(['project_id' => $project->id]);
    $other->metadata()->create(['environment' => 'local', 'url' => 'https://app.test/baseXone/page', 'source' => 'embedded_widget']);

    $this->actingAs($user)->get('/connect')->assertInertia(fn (Assert $page) => $page->where('environments.0.feedback_report', null));
});

test('Feedback activation changes only the selected environment and keeps guest intake disabled', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $user->id, 'client_id' => null, 'external_widget_enabled' => false]);
    $environment = connectionEnvironment(['project_id' => $project->id, 'external_widget_enabled' => false, 'external_widget_guest_submissions_enabled' => false]);
    $other = connectionEnvironment(['project_id' => $project->id, 'environment' => 'production', 'external_widget_enabled' => false]);

    $this->actingAs($user)->patch(route('connection.update', [$project, $environment]), ['feedback_enabled' => true])
        ->assertRedirect(route('connection.index', ['project_id' => $project->id]));
    expect($environment->fresh()->external_widget_enabled)->toBeTrue()
        ->and($environment->fresh()->external_widget_guest_submissions_enabled)->toBeFalse()
        ->and($other->fresh()->external_widget_enabled)->toBeFalse()
        ->and($project->fresh()->external_widget_enabled)->toBeFalse();
});

test('shared members and unrelated environments cannot manage a connection', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $project = Project::factory()->create(['author_id' => $owner->id, 'client_id' => null]);
    ProjectUser::factory()->create(['project_id' => $project->id, 'user_id' => $member->id, 'user_email' => $member->email, 'user_name' => $member->name]);
    $environment = connectionEnvironment(['project_id' => $project->id]);
    $this->actingAs($member)->get('/connect?project_id='.$project->id)->assertNotFound();
    $this->patch(route('connection.update', [$project, $environment]), ['feedback_enabled' => true])->assertNotFound();

    $other = connectionEnvironment();
    $this->actingAs($owner)->patch(route('connection.update', [$project, $other]), ['feedback_enabled' => true])->assertNotFound();
});
