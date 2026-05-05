<?php

use Illuminate\Notifications\Messages\MailMessage;

test('notification mail does not render Laravel default branding', function () {
    config([
        'app.name' => 'Laravel',
        'app.url' => 'https://shift.wyxos.com',
    ]);

    $html = (new MailMessage)
        ->subject('SHIFT notification')
        ->line('A SHIFT notification is ready.')
        ->action('Open SHIFT', 'https://shift.wyxos.com/tasks')
        ->render();

    $this->assertStringContainsString('SHIFT', $html);
    $this->assertStringNotContainsString('notification-logo', $html);
    $this->assertStringNotContainsString('Laravel Logo', $html);
    $this->assertStringNotContainsString('Laravel', $html);
});
