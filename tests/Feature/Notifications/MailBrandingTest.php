<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

test('notification mail does not render Laravel default branding', function () {
    config([
        'app.name' => 'Laravel',
        'app.url' => 'https://shift.source.example',
    ]);

    $html = (new MailMessage)
        ->subject('SHIFT notification')
        ->line('A SHIFT notification is ready.')
        ->action('Open SHIFT', 'https://shift.source.example/tasks')
        ->render();

    $this->assertStringContainsString('SHIFT', $html);
    $this->assertStringNotContainsString('notification-logo', $html);
    $this->assertStringNotContainsString('Laravel Logo', $html);
    $this->assertStringNotContainsString('Laravel', $html);
});

test('notification emails deliver the branded HTML and readable text with the original action URL', function () {
    config(['app.url' => 'https://shift.example.test', 'mail.default' => 'array']);
    $url = 'https://consumer.example.test/shift/tasks?task=322&tab=comments';
    $mail = (new MailMessage)
        ->subject('A task update')
        ->greeting('Task updated')
        ->line('The request is ready to review.')
        ->action('View task', $url);
    $notification = new class($mail) extends Notification
    {
        public function __construct(private MailMessage $mail) {}

        public function toMail(object $notifiable): MailMessage
        {
            return $this->mail;
        }
    };
    $sent = app(MailChannel::class)->send((new AnonymousNotifiable)->route('mail', '1@example.com'), $notification);
    $message = $sent->getOriginalMessage();
    $html = $message->getHtmlBody();
    $text = $message->getTextBody();

    expect($html)->toContain('https://shift.example.test/brand/shift-wordmark-email.png', 'alt="SHIFT"', '#0f172a', '#2563eb', 'Or open this link in your browser:')
        ->not->toContain('Button not working', 'having trouble', 'Regards,');
    expect($text)->toContain('Task updated', 'The request is ready to review.', $url, 'Or open this link in your browser:')
        ->not->toContain('<table', '<img', '<style', '<br');
    expect($message->getTo()[0]->getAddress())->toBe('1@example.com');

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//table[contains(@class,"inner-body")]//td[contains(@class,"header")]')->length)->toBe(1)
        ->and($xpath->query('//a[@href="'.$url.'"]')->length)->toBe(2);

    if ($directory = getenv('SHIFT_MAIL_REVIEW_DIR')) {
        @mkdir($directory, 0777, true);
        file_put_contents($directory.'/task-update.html', $html);
    }
});

test('authentication emails retain their signed and token links in the shared brand layout', function (string $notification) {
    config(['app.url' => 'https://shift.example.test']);
    $user = User::factory()->make(['id' => 123, 'email' => '1@example.com']);
    $message = ($notification === 'reset' ? new ResetPassword('synthetic-token') : new VerifyEmail)->toMail($user);
    $html = (string) $message->render();
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

    expect($html)->toContain('/brand/shift-wordmark-email.png', e($message->actionUrl), $message->actionText)
        ->and($text)->toContain($message->actionUrl, $message->actionText)
        ->not->toContain('<table', '<img');

    if ($directory = getenv('SHIFT_MAIL_REVIEW_DIR')) {
        @mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$notification.'.html', $html);
    }
})->with(['reset', 'verify']);
