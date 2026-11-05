<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps quick reactions at the visible edge while scrolling long messages', function (int $width) {
    $user = User::factory()->create(['name' => 'Alex Morgan']);
    $project = Project::factory()->withAuthor($user->id)->create();
    $task = Task::factory()->for($project)->create(['title' => 'Reaction toolbar scrolling', 'description' => 'Review quick reactions on a long code message.']);
    $task->submitter()->associate($user)->save();
    $message = new TaskThread([
        'task_id' => $task->id, 'type' => 'external', 'sender_name' => 'Sam Rivera',
        'content' => '<pre><code>'.htmlspecialchars(implode("\n", array_fill(0, 65, '<div class="example">Long code message</div>'))).'</code></pre>',
    ]);
    $message->sender()->associate($user)->save();
    $this->actingAs($user);
    $page = visit("/tasks?task={$task->id}")->inDarkMode()->resize($width, 960);
    if ($width < 1024) {
        $page->click('[data-testid="edit-mobile-pane-comments"]');
    }
    $page->assertVisible("[data-testid=comment-bubble-{$message->id}]");
    $page->script(<<<'JS'
        (() => {
            const h = document.querySelector('.task-comments-history');
            const m = h.querySelector('.task-comment-menu');
            h.scrollTop += m.getBoundingClientRect().top - h.getBoundingClientRect().top + 250;
            m.dispatchEvent(new PointerEvent('pointerenter'));
            m.querySelector('.task-comment-reaction-palette button').focus({preventScroll: true});
        })()
        JS);
    foreach ([-150, 250, -100] as $delta) {
        $page->script("document.querySelector('.task-comments-history').scrollTop += {$delta}");
        $page->assertScript(<<<'JS'
            (() => {
                const h = document.querySelector('.task-comments-history').getBoundingClientRect();
                const p = document.querySelector('.task-comment-reaction-palette').getBoundingClientRect();
                const top = Math.max(h.top, document.querySelector('[data-testid="task-report-context"]')?.getBoundingClientRect().bottom ?? h.top);
                return p.top >= top && p.top <= top + 8 && p.left >= h.left && p.right <= h.right;
            })()
            JS);
    }
    $page->screenshot(false, "reaction-scroll-{$width}.png");
    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if ($destination) {
        @mkdir($destination, 0777, true);
        copy(__DIR__."/Screenshots/reaction-scroll-{$width}.png", $destination."/reaction-scroll-{$width}.png");
    }
})->with([1440, 834, 390]);
