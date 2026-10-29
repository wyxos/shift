<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps long fatal diagnostics readable at each sheet width', function (int $width, string $motion) {
    $user = User::factory()->create(['name' => 'Error Reviewer', 'email' => '1@example.com']);
    $project = Project::factory()->withAuthor($user->id)->create(['name' => 'Synthetic error review']);
    $file = 'vendor/symfony/error-handler/ErrorRenderer/HtmlErrorRenderer.php';
    $summary = 'Uncaught Error: Call to undefined function Symfony\\Component\\ErrorHandler\\ErrorRenderer\\highlight_file() in /app/'.$file.':273';
    $trace = implode(' ', array_map(fn (int $index): string => '#'.$index.' /app/vendor/symfony/error-handler/Resources/views/trace.html.php(36): Symfony\\Component\\ErrorHandler\\ErrorRenderer\\HtmlErrorRenderer->fileExcerpt()', range(0, 30)));
    $message = $summary.' Stack trace: '.$trace.' thrown';
    $sha = '7e671aa394607f5a58f2790c92fd508df69be466';
    $task = Task::factory()->for($project)->create([
        'title' => 'FatalError at '.$file.':273',
        'status' => 'pending',
        'priority' => 'high',
        'description' => '',
        'error_signature' => str_repeat('a', 64),
        'error_source' => 'backend',
        'error_environment' => 'production',
        'error_name' => 'FatalError',
        'error_culprit_file' => $file,
        'error_culprit_line' => 273,
        'error_occurrences_count' => 1,
        'error_first_seen_at' => now(),
        'error_last_seen_at' => now(),
    ]);
    $task->submitter()->associate($user)->save();
    $event = $task->errorOccurrences()->create([
        'number' => 1,
        'source' => 'backend',
        'environment' => 'production',
        'error_name' => 'FatalError',
        'exception_class' => 'Symfony\\Component\\ErrorHandler\\Error\\FatalError',
        'message' => $message,
        'git_sha' => $sha,
        'culprit_file' => $file,
        'culprit_line' => 273,
        'request_method' => 'POST',
        'request_url' => 'https://consumer.test/',
        'stacktrace' => ['frames' => [['file' => $file, 'line' => 273, 'function' => null, 'in_app' => false]]],
        'payload' => ['message' => $message],
        'received_at' => now(),
        'occurred_at' => now(),
    ]);
    $this->actingAs($user);

    $page = visit("/error-reports?task={$task->id}", ['reducedMotion' => $motion])->resize($width, 1000)
        ->assertSee('Events')
        ->assertVisible("#error-event-trigger-{$event->id}")
        ->assertScript("(() => { const el = document.querySelector('#error-event-trigger-{$event->id} .line-clamp-2'); return el.getBoundingClientRect().height <= parseFloat(getComputedStyle(el).lineHeight) * 2 + 1; })()")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertSee('7e671aa3')
        ->assertAttribute('[data-testid="error-occurrence-release"]', 'title', $sha)
        ->assertScript('document.querySelector("[data-testid=error-occurrence-full-message]").open === false')
        ->keys('[data-testid="error-occurrence-full-message"] summary', 'Enter')
        ->assertScript('document.querySelector("[data-testid=error-occurrence-full-message]").open === true')
        ->assertScript('document.querySelector("[data-testid=error-occurrence-full-message] pre").textContent.includes("#30 ")')
        ->assertScript('document.querySelector("[data-testid=error-occurrence-full-message] pre").getBoundingClientRect().height <= 289')
        ->keys('[data-testid="error-occurrence-full-message"] summary', 'Enter')
        ->assertScript('document.querySelector("[data-testid=error-occurrence-full-message]").open === false')
        ->assertScript('document.activeElement.matches("[data-testid=error-occurrence-full-message] summary")')
        ->assertNoJavascriptErrors();

    $destination = getenv('SHIFT_BROWSER_REVIEW_DIR');
    if (is_string($destination) && $destination !== '') {
        $filename = "shift-error-event-{$width}.png";
        $page->script('document.querySelector("[data-testid=error-occurrences-panel]").scrollTop = 0');
        $page->screenshot(false, $filename);
        @mkdir($destination, 0777, true);
        copy(__DIR__.'/Screenshots/'.$filename, rtrim($destination, '/').'/'.$filename);
    }

    $page->keys("#error-event-trigger-{$event->id}", 'Enter')
        ->assertAttribute("#error-event-trigger-{$event->id}", 'aria-expanded', 'false')
        ->keys("#error-event-trigger-{$event->id}", 'Enter')
        ->assertAttribute("#error-event-trigger-{$event->id}", 'aria-expanded', 'true');
})->with([
    'desktop' => [1440, 'no-preference'],
    'tablet' => [768, 'no-preference'],
    'phone' => [390, 'no-preference'],
    'phone reduced motion' => [390, 'reduce'],
]);
