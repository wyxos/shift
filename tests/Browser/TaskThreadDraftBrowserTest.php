<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function copyBrowserReviewScreenshot(string $filename): void
{
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');

    if (! is_string($destination) || $destination === '') {
        return;
    }

    @mkdir($destination, 0777, true);
    copy(__DIR__.'/Screenshots/'.$filename, rtrim($destination, '/').'/'.$filename);
}

final class TaskThreadBrowserSendMiddleware
{
    public static ?int $taskId = null;

    public static int $sendRequestCount = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        if (self::$taskId !== null
            && $request->isMethod('POST')
            && $request->path() === 'tasks/'.self::$taskId.'/threads') {
            self::$sendRequestCount++;

            if (self::$sendRequestCount === 2) {
                return new JsonResponse(['message' => 'Controlled browser send failure'], 503);
            }
        }

        return $next($request);
    }
}

it('shows a private draft and lets its author publish it from desktop and mobile comments', function () {
    $user = User::factory()->create(['name' => 'Draft Author']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create([
        'title' => 'Browser draft publishing proof',
        'description' => '',
    ]);
    $task->submitter()->associate($user)->save();

    $draft = new TaskThread([
        'task_id' => $task->id,
        'type' => 'external',
        'content' => '<p>Prepared reply for human review</p>',
        'sender_name' => $user->name,
        'is_draft' => true,
    ]);
    $draft->sender()->associate($user);
    $draft->save();

    $this->actingAs($user);
    $draftAction = "[data-testid=\"publish-draft-{$draft->id}\"]";

    $page = visit("/tasks?task={$task->id}")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->assertVisible('[data-testid="task-comments-pane"]')
        ->assertSee('Prepared reply for human review')
        ->assertVisible($draftAction)
        ->assertAttribute($draftAction, 'aria-label', 'Publish draft')
        ->assertScript("!document.querySelector('[data-testid=\"comment-bubble-{$draft->id}\"]').contains(document.querySelector('{$draftAction}'))")
        ->assertScript('document.querySelectorAll("[data-testid^=publish-draft-]").length === 1')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=draft-action-label]")).visibility === "visible"')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=publish-action-label]")).visibility === "hidden"')
        ->assertScript("(window.draftActionWidth = document.querySelector('{$draftAction}').getBoundingClientRect().width) > 0")
        ->screenshot(false, 'task-thread-draft-desktop.png');
    copyBrowserReviewScreenshot('task-thread-draft-desktop.png');

    $page->hover($draftAction)
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=draft-action-label]")).visibility === "hidden"')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=publish-action-label]")).visibility === "visible"')
        ->assertScript("document.querySelector('{$draftAction}').getBoundingClientRect().width === window.draftActionWidth")
        ->screenshot(false, 'task-thread-draft-hover-desktop.png');
    copyBrowserReviewScreenshot('task-thread-draft-hover-desktop.png');

    $page->hover('[data-testid="comments-editor"]')
        ->keys($draftAction, 'Shift')
        ->assertScript("document.activeElement.matches('{$draftAction}:focus-visible')")
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=draft-action-label]")).visibility === "hidden"')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=publish-action-label]")).visibility === "visible"')
        ->screenshot(false, 'task-thread-draft-focus-desktop.png');
    copyBrowserReviewScreenshot('task-thread-draft-focus-desktop.png');

    $mobilePage = visit("/tasks?task={$task->id}")->on()->mobile()
        ->assertNoSmoke()
        ->click('[data-testid="edit-mobile-pane-comments"]')
        ->assertVisible('[data-testid="task-comments-pane"]')
        ->assertVisible($draftAction)
        ->assertScript('matchMedia("(hover: none)").matches')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=draft-action-label]")).visibility === "hidden"')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=publish-action-label]")).visibility === "visible"')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(false, 'task-thread-draft-mobile.png');
    copyBrowserReviewScreenshot('task-thread-draft-mobile.png');

    $mobilePage
        ->click($draftAction)
        ->waitForText('Prepared reply for human review')
        ->assertNotPresent($draftAction)
        ->assertNoSmoke();

    expect($draft->refresh()->is_draft)->toBeFalse()
        ->and($draft->published_at)->not->toBeNull();
});

it('clears the composer optimistically and offers retry after a browser send failure', function () {
    $user = User::factory()->create(['name' => 'Send Author']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create([
        'title' => 'Browser optimistic send proof',
        'description' => '',
    ]);
    $task->submitter()->associate($user)->save();

    TaskThreadBrowserSendMiddleware::$taskId = $task->id;
    TaskThreadBrowserSendMiddleware::$sendRequestCount = 0;
    Route::prependMiddlewareToGroup('web', TaskThreadBrowserSendMiddleware::class);
    $this->actingAs($user);

    $page = visit("/tasks?task={$task->id}")
        ->resize(1440, 900)
        ->assertNoSmoke()
        ->click('[data-testid="comments-editor"] .ProseMirror')
        ->type('[data-testid="comments-editor"] .ProseMirror', 'First browser comment');
    $page->script(<<<JS
        (() => {
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            const target = '/tasks/{$task->id}/threads';
            let delayed = false;

            XMLHttpRequest.prototype.open = function (method, url, ...args) {
                this.__shiftDelayFirstThreadSend = !delayed && method === 'POST' && String(url).includes(target);

                return originalOpen.call(this, method, url, ...args);
            };

            XMLHttpRequest.prototype.send = function (body) {
                if (!this.__shiftDelayFirstThreadSend) {
                    return originalSend.call(this, body);
                }

                delayed = true;
                const request = this;
                window.setTimeout(() => originalSend.call(request, body), 2000);
            };
        })();
    JS);
    $page->script("setTimeout(() => document.querySelector('[data-testid=\"toolbar-send\"]').click(), 0)");
    usleep(100000);
    $page
        ->wait(0.2)
        ->assertScript('document.querySelector("[data-testid=\'comments-editor\'] .ProseMirror").textContent.trim() === ""')
        ->assertScript('[...document.querySelectorAll("[role=status]")].some((item) => item.textContent.includes("Sending..."))')
        ->screenshot(false, 'task-thread-sending.png');
    copyBrowserReviewScreenshot('task-thread-sending.png');

    $page
        ->wait(2.5)
        ->assertDontSee('Sending...')
        ->assertSee('First browser comment')
        ->click('[data-testid="comments-editor"] .ProseMirror')
        ->type('[data-testid="comments-editor"] .ProseMirror', 'Retry this browser comment')
        ->click('[data-testid="toolbar-send"]')
        ->assertScript('document.querySelector("[data-testid=\'comments-editor\'] .ProseMirror").textContent.trim() === ""')
        ->assertVisible('[data-testid^="retry-comment-"]')
        ->assertSee('Failed to send')
        ->screenshot(false, 'task-thread-send-failed.png');
    copyBrowserReviewScreenshot('task-thread-send-failed.png');

    $page->click('[data-testid^="retry-comment-"]')
        ->assertScript('document.querySelectorAll("[data-testid=\'task-comments-pane\'] .shift-rich").length === 2')
        ->waitForText('Retry this browser comment')
        ->wait(0.5)
        ->assertDontSee('Sending...')
        ->assertNotPresent('[data-testid^="retry-comment-"]')
        ->assertScript('document.querySelectorAll("[data-testid=\'task-comments-pane\'] .shift-rich").length === 2')
        ->assertNoSmoke();

    expect(TaskThread::query()->where('task_id', $task->id)->where('content', 'like', '%Retry this browser comment%')->count())->toBe(1);
});
