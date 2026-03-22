<?php

use App\Support\RichContentSanitizer;

test('sanitizes dangerous html while preserving supported rich content markup', function () {
    $sanitizer = new RichContentSanitizer;

    $sanitized = $sanitizer->sanitize(implode('', [
        '<p>Hello</p>',
        '<script>alert(1)</script>',
        '<blockquote class="shift-reply extra" data-reply-to="42" onclick="alert(1)"><p>Reply</p></blockquote>',
        '<p><img src="/attachments/1/download" class="editor-tile extra" onerror="alert(1)"></p>',
        '<pre><code class="language-js extra">const x = 1;</code></pre>',
        '<p><a href="javascript:alert(1)" target="_blank">bad</a></p>',
    ]));

    expect($sanitized)->toContain('data-reply-to="42"');
    expect($sanitized)->toContain('class="shift-reply"');
    expect($sanitized)->toContain('class="editor-tile"');
    expect($sanitized)->toContain('class="language-js"');
    expect($sanitized)->not->toContain('<script');
    expect($sanitized)->not->toContain('onclick');
    expect($sanitized)->not->toContain('onerror');
    expect($sanitized)->not->toContain('javascript:');
});

test('leaves non html content untouched', function () {
    $sanitizer = new RichContentSanitizer;

    expect($sanitizer->sanitize('**hello**'))->toBe('**hello**');
});
