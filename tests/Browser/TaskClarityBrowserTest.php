<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

final class TaskClaritySaveMiddleware
{
    public static ?int $taskId = null;

    public static bool $failNextSave = true;

    public function handle(Request $request, Closure $next): mixed
    {
        if (self::$failNextSave && $request->isMethod('PUT') && $request->path() === 'tasks/'.self::$taskId) {
            self::$failNextSave = false;

            return new JsonResponse(['message' => 'Please try again.'], 503);
        }

        return $next($request);
    }
}

function captureTaskClarityReview($page, string $name): void
{
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (! is_string($destination) || $destination === '') {
        return;
    }

    $page->screenshot(false, $name);
    @mkdir($destination, 0777, true);
    copy(__DIR__.'/Screenshots/'.$name, rtrim($destination, '/').'/'.$name);
}

it('explains automatic saving and preserves changes through failure and retry', function () {
    $user = User::factory()->create(['name' => 'Alex Taylor']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Review export options', 'description' => '<p>Include selected filters in the export.</p>']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);
    TaskClaritySaveMiddleware::$taskId = $task->id;
    TaskClaritySaveMiddleware::$failNextSave = true;
    Route::prependMiddlewareToGroup('web', TaskClaritySaveMiddleware::class);

    $page = visit("/tasks?task={$task->id}")->resize(1440, 900)
        ->assertSee('Changes save automatically.')
        ->fill('[data-testid="task-edit-title"]', 'Export selected filters')
        ->waitForText('Changes couldn’t be saved.')
        ->assertValue('[data-testid="task-edit-title"]', 'Export selected filters')
        ->assertVisible('[data-testid="task-save-retry"]');
    captureTaskClarityReview($page, 'task-save-error-desktop.png');

    $page->click('[data-testid="task-save-retry"]')
        ->waitForText('All changes saved')
        ->assertNotPresent('[data-testid="task-save-retry"]');
    expect($task->refresh()->title)->toBe('Export selected filters');
    captureTaskClarityReview($page, 'task-sheet-desktop.png');

    $page = visit("/tasks?task={$task->id}")->resize(390, 844)
        ->click('[data-testid="edit-mobile-pane-comments"]')
        ->assertSee('Visible to task collaborators, including external collaborators.')
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=comments-editor] [data-testid^=toolbar-], [data-testid^=thread-audience-], [data-testid^=edit-mobile-pane-], [data-testid=task-edit-back]")).filter(el => el.tagName === "BUTTON").every(el => {const r = el.getBoundingClientRect(); return r.width >= 44 && r.height >= 44;})')
        ->assertScript('document.documentElement.scrollWidth <= innerWidth');
    captureTaskClarityReview($page, 'task-comments-mobile.png');
    $page->click('[data-testid="thread-audience-team"]')
        ->assertSee('Visible to the internal team only.')
        ->click('[data-testid="edit-mobile-pane-details"]');
    captureTaskClarityReview($page, 'task-sheet-mobile.png');
});

it('uses matching local timestamps and supports direct title search recovery', function () {
    $user = User::factory()->create();
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Review export options', 'status' => 'pending', 'created_at' => '2026-06-04 15:11:00']);
    $task->submitter()->associate($user)->save();
    $this->actingAs($user);

    $page = visit('/tasks')->resize(1440, 900)
        ->assertVisible('[data-testid="task-list-search"]')
        ->assertSee('Review export options');
    captureTaskClarityReview($page, 'task-list-desktop.png');
    $page->click('[data-testid="task-title-'.$task->id.'"]')
        ->assertVisible('[data-testid="edit-task-created-at"]')
        ->assertScript('document.querySelector("[data-testid=edit-task-created-at] time").textContent === document.querySelector("[data-testid=task-created-at-'.$task->id.']").textContent');
    $page->click('[data-testid="task-edit-back"]')
        ->fill('[data-testid="task-list-search"]', 'No matching export')
        ->click('[data-testid="task-list-search-submit"]')
        ->assertVisible('[data-testid="task-list-clear-search"]')
        ->assertVisible('[data-testid="task-list-reset-filters"]');
    captureTaskClarityReview($page, 'task-search-empty-desktop.png');
    $page->click('[data-testid="task-list-clear-search"]')
        ->assertSee('Review export options')
        ->resize(390, 844)
        ->assertVisible('[data-testid="task-list-search"]')
        ->assertScript('document.querySelector("[data-testid=task-list-search]").getBoundingClientRect().width >= 180')
        ->assertScript('document.documentElement.scrollWidth <= innerWidth')
        ->assertNoSmoke();
    captureTaskClarityReview($page, 'task-list-mobile.png');
});
