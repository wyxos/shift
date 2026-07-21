<?php

use App\Services\TaskEmail\TaskEmailMessageParser;
use Illuminate\Http\UploadedFile;

test('email parser extracts readable content and attachment names without decoding attachment data', function () {
    $email = UploadedFile::fake()->createWithContent('message.eml', implode("\r\n", [
        'Subject: Multipart message',
        'From: sender@example.com',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="shift-boundary"',
        '',
        '--shift-boundary',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Readable message body.',
        '--shift-boundary',
        'Content-Type: application/octet-stream; name="../payload.php"',
        'Content-Disposition: attachment; filename="../payload.php"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('<?php system($_GET["cmd"]);'),
        '--shift-boundary--',
    ]));

    $parsed = (new TaskEmailMessageParser)->parse($email);

    expect($parsed['body_text'])->toBe('Readable message body.')
        ->and($parsed['attachments'])->toBe(['payload.php']);
});

test('email parser rejects messages with excessive MIME parts', function () {
    $parts = [];

    for ($index = 0; $index < 101; $index++) {
        $parts[] = implode("\r\n", [
            '--shift-boundary',
            'Content-Type: text/plain',
            '',
            'Part '.$index,
        ]);
    }

    $email = UploadedFile::fake()->createWithContent('message.eml', implode("\r\n", [
        'Subject: Too many parts',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="shift-boundary"',
        '',
        ...$parts,
        '--shift-boundary--',
    ]));

    expect(fn () => (new TaskEmailMessageParser)->parse($email))
        ->toThrow(InvalidArgumentException::class, 'too many MIME parts');
});

test('email parser rejects deeply nested MIME messages', function () {
    $entity = implode("\r\n", [
        'Content-Type: text/plain',
        '',
        'Nested body',
    ]);

    for ($depth = 0; $depth < 11; $depth++) {
        $boundary = 'nested-'.$depth;
        $entity = implode("\r\n", [
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
            '',
            '--'.$boundary,
            $entity,
            '--'.$boundary.'--',
        ]);
    }

    $email = UploadedFile::fake()->createWithContent('message.eml', implode("\r\n", [
        'Subject: Deeply nested',
        'MIME-Version: 1.0',
        $entity,
    ]));

    expect(fn () => (new TaskEmailMessageParser)->parse($email))
        ->toThrow(InvalidArgumentException::class, 'nested too deeply');
});

test('email parser rejects files without recognizable message headers', function () {
    $email = UploadedFile::fake()->createWithContent('message.eml', '<?php echo "not email";');

    expect(fn () => (new TaskEmailMessageParser)->parse($email))
        ->toThrow(InvalidArgumentException::class, 'recognizable email headers');
});
