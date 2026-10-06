<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reveals only the hovered or keyboard-focused row actions without moving content', function (string $motion) {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $tasks = Task::factory()->count(2)->for($project)->create(['status' => 'pending']);
    foreach ($tasks as $task) {
        $task->submitter()->associate($user)->save();
    }
    $this->actingAs($user);
    $task = $tasks->first();
    $other = $tasks->last();
    $action = "[data-testid=task-open-{$task->id}]";
    $delete = "[data-testid=task-delete-{$task->id}]";
    $otherAction = "[data-testid=task-open-{$other->id}]";
    $title = "[data-testid=task-title-{$task->id}]";

    $page = visit('/tasks', ['reducedMotion' => $motion])->resize(1440, 900)->assertNoSmoke()
        ->hover('h1:first-of-type')
        ->assertScript("getComputedStyle(document.querySelector('{$action}')).opacity === '0'")
        ->assertScript("getComputedStyle(document.querySelector('{$delete}')).pointerEvents === 'none'")
        ->assertScript("(window.rowActionLeft = document.querySelector('{$action}').getBoundingClientRect().left) > 0")
        ->hover($title)
        ->assertScript("getComputedStyle(document.querySelector('{$action}')).opacity === '1'")
        ->assertScript("getComputedStyle(document.querySelector('{$delete}')).opacity === '1'")
        ->assertScript("getComputedStyle(document.querySelector('{$otherAction}')).opacity === '0'")
        ->assertScript("document.querySelector('{$action}').getBoundingClientRect().left === window.rowActionLeft")
        ->hover('h1:first-of-type')
        ->keys($title, 'Tab')
        ->assertScript("document.activeElement.matches('{$action}')")
        ->assertScript("getComputedStyle(document.querySelector('{$action}')).opacity === '1'")
        ->assertScript("parseFloat(getComputedStyle(document.querySelector('{$action}')).transitionDuration) <= ".($motion === 'reduce' ? '0.001' : '0.15'))
        ->keys($action, 'Enter')
        ->assertVisible('[data-testid=task-edit-title]')
        ->assertNoSmoke();
})->with(['no-preference', 'reduce']);

it('keeps row actions visible and usable on touch screens', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['status' => 'pending']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);
    $action = "[data-testid=task-compact-open-{$task->id}]";
    $delete = "[data-testid=task-compact-delete-{$task->id}]";

    visit('/tasks')->on()->mobile()
        ->assertNoSmoke()
        ->assertScript('matchMedia("(hover: none)").matches')
        ->assertScript("getComputedStyle(document.querySelector('{$action}')).opacity === '1'")
        ->assertScript("getComputedStyle(document.querySelector('{$delete}')).pointerEvents !== 'none'")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click($action)
        ->assertVisible('[data-testid=task-edit-title]')
        ->assertNoSmoke();
});
