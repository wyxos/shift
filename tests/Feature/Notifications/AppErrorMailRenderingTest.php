<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppErrorReportedNotification;
use Illuminate\Mail\Markdown;

it('renders each error alert with structured details and an equivalent plain text part', function (string $reason, string $heading, string $event) {
    $project = Project::factory()->make(['name' => 'Home Installation']);
    $task = Task::factory()->make([
        'title' => 'SalesforceRequestException at app/BritishGas/SalesforceEndpoint.php:282',
        'error_name' => 'SalesforceRequestException',
        'error_culprit_file' => 'app/BritishGas/SalesforceEndpoint.php',
        'error_culprit_line' => 282,
        'error_environment' => 'production',
        'error_occurrences_count' => 9,
    ])->setRelation('project', $project);
    $url = 'https://shift.example.test/error-reports?task=322';
    $mail = (new AppErrorReportedNotification($task, $reason, $url))->toMail(User::factory()->make());
    $html = (string) $mail->render();
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

    expect($html)->toContain($heading, $event, 'SalesforceRequestException', 'SalesforceEndpoint.php:282', 'Home Installation', 'production', 'Occurrences', $url, 'Error report')
        ->not->toContain('quiet period', 'retries', 'recorded silently', 'Reopened')
        ->and($text)->toContain($heading, $event, $task->title, 'Project: Home Installation', 'Environment: production', 'Occurrences: 9', $url)
        ->not->toContain('<table', '<span', '<wbr', '<img', 'quiet period', 'retries', 'recorded silently', 'Reopened');
    expect($mail->introLines)->not->toContain('This open error has returned after a quiet period. Further retries are recorded silently.');
    expect($mail->subject)->toContain($event === 'Reported' ? 'Home Installation -' : 'Home Installation '.$event.' -');

    if ($directory = getenv('SHIFT_MAIL_REVIEW_DIR')) {
        @mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$reason.'.html', $html);
    }
})->with([
    'reported' => ['reported', 'Exception detected', 'Reported'],
    'recurring' => ['recurring', 'Exception detected', 'Recurring'],
    'reopened' => ['reopened', 'Exception detected', 'Regressed'],
]);

it('escapes error metadata and handles absent environment and source fields', function () {
    $project = Project::factory()->make(['name' => '<img src=x onerror="alert(1)">']);
    $task = Task::factory()->make([
        'title' => '<script>alert(1)</script>',
        'error_name' => null,
        'error_culprit_file' => null,
        'error_environment' => null,
        'error_occurrences_count' => null,
    ])->setRelation('project', $project);
    $mail = (new AppErrorReportedNotification($task, 'reported', 'https://shift.example.test/error-reports'))->toMail(User::factory()->make());
    $html = (string) $mail->render();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//script|//*[@onerror or @onload]')->length)->toBe(0)
        ->and($html)->toContain('N/A', '&lt;script&gt;', '&lt;img');
});

it('uses the canonical exception class when an error also supplies a different name', function () {
    $task = Task::factory()->make([
        'title' => 'RuntimeException at app/Foo.php:10',
        'error_exception_class' => 'Vendor\\RuntimeException',
        'error_name' => 'TypeError',
        'error_culprit_file' => 'app/Foo.php',
        'error_culprit_line' => 10,
    ])->setRelation('project', Project::factory()->make());
    $mail = (new AppErrorReportedNotification($task, 'reported', 'https://shift.example.test/error-reports'))->toMail(User::factory()->make());
    $html = (string) $mail->render();
    $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

    expect($mail->subject)->toContain('RuntimeException')
        ->and($html)->toContain('RuntimeException')->not->toContain('TypeError')
        ->and($text)->toContain('RuntimeException at app/Foo.php:10')->not->toContain('TypeError');
});
