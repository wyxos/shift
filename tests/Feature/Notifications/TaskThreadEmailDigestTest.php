<?php

use App\Notifications\TaskThreadEmailDigest;

test('grouped thread mail renders each author and readable message in order', function () {
    $notification = new TaskThreadEmailDigest([
        'task_title' => 'Example task',
        'url' => 'https://portal.example.test/tasks?task=123',
        'messages' => [
            ['author' => 'First author', 'content' => '<p>First &amp; second</p><p>Next line</p>'],
            ['author' => 'Second author', 'content' => '<p><strong>Another reply</strong></p>'],
        ],
    ]);
    $mail = $notification->toMail(new stdClass);
    $html = (string) $mail->render();

    expect($notification->via(new stdClass))->toBe(['mail'])
        ->and($mail->subject)->toBe('SHIFT: 2 new replies on Example task')
        ->and($mail->introLines)->toBe([
            '2 new messages were posted.',
            'First author — First & second Next line',
            'Second author — Another reply',
        ])
        ->and($mail->actionText)->toBe('View Thread')
        ->and($mail->actionUrl)->toBe('https://portal.example.test/tasks?task=123')
        ->and($html)->toContain('First author', 'First &amp; second Next line', 'Second author', 'Another reply')
        ->not->toContain('&lt;p&gt;', '&lt;strong&gt;');
});

test('single thread mail uses singular wording and caps long previews', function () {
    $notification = new TaskThreadEmailDigest([
        'task_title' => 'Example task',
        'url' => 'https://portal.example.test/tasks?task=123',
        'messages' => [['author' => '', 'content' => str_repeat('message ', 100)]],
    ]);
    $mail = $notification->toMail(new stdClass);

    expect($mail->subject)->toBe('SHIFT: New reply on Example task')
        ->and($mail->introLines[0])->toBe('A new message was posted.')
        ->and($mail->introLines[1])->toStartWith('User — message ')
        ->and(mb_strlen($mail->introLines[1]))->toBeLessThan(260);
});
