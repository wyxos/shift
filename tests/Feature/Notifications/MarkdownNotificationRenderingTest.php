<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppErrorReportedNotification;
use App\Notifications\TaskCreationNotification;
use Illuminate\Mail\Markdown;

test('task notifications render bounded adversarial Markdown through the mail layout', function (string $title) {
    expect(mb_strlen($title))->toBeLessThanOrEqual(255);

    $project = Project::factory()->make(['name' => $title]);
    $task = Task::factory()->make(['title' => $title, 'status' => 'pending', 'priority' => 'high']);
    $task->setRelation('project', $project);
    $recipient = User::factory()->make();
    $url = 'https://shift.example.test/tasks?task=123';

    foreach ([new TaskCreationNotification($task, $url), new AppErrorReportedNotification($task, 'reported', $url)] as $notification) {
        $message = $notification->toMail($recipient);
        $html = (string) $message->render();

        expect($message->subject)->toContain($title)
            ->and($html)->toContain('SHIFT', $url, 'Please do not reply to this email directly.')
            ->and(strlen($html))->toBeLessThan(100000);

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);

        expect($xpath->query('//script|//iframe|//*[@onload or @onerror or @onclick]')->length)->toBe(0);
    }
})->with([
    'ordinary formatting' => ['Review **urgent** task & project'],
    'emphasis delimiters' => [str_repeat('*_', 127)],
    'unclosed code fence' => ['```'.str_repeat('`', 250)],
    'reference link delimiters' => [str_repeat('[x][', 63)],
    'table-like numeric lines' => [str_repeat("12345678\n", 28)],
    'attribute-like text' => ['[report]{on'."\f".'click="alert(1)" '.str_repeat('x=1 ', 35).'}'],
    'raw HTML text' => ['<img src=x onerror="alert(1)"><script>alert(1)</script>'],
]);

test('the Laravel Markdown renderer handles bounded pipe-free paragraphs and preserves tables', function () {
    $paragraph = str_repeat("12345678\n", 2048);
    $html = (string) Markdown::parse($paragraph);

    expect(substr_count($html, '12345678'))->toBe(2048)
        ->and(strlen($html))->toBeLessThan(20000);

    $table = (string) Markdown::parse("| Task | Status |\n| --- | --- |\n| Review | Pending |");

    expect($table)->toContain('<table>', '<th>Task</th>', '<td>Pending</td>');
});
