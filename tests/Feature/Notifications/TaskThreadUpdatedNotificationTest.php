<?php

use App\Models\User;
use App\Notifications\TaskThreadUpdated;

test('thread update mail preview renders rich content as plain text', function () {
    $notification = new TaskThreadUpdated([
        'type' => 'external',
        'task_id' => 123,
        'task_title' => 'Production QA task',
        'thread_id' => 456,
        'content' => '<p>Production-backed embedded-client QA comment &amp; marker.</p><p>Marker: qa-marker</p>',
        'url' => 'https://shift.wyxos.com/tasks?task=123',
    ]);

    $mail = $notification->toMail(User::factory()->create());

    expect($mail->introLines)->toContain('Preview: "Production-backed embedded-client QA comment & marker. Marker: qa-marker"');
    expect($notification->toArray(new stdClass)['content'])
        ->toBe('<p>Production-backed embedded-client QA comment &amp; marker.</p><p>Marker: qa-marker</p>');
});
