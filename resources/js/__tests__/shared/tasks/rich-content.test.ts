import { buildReplyQuoteHtml, extractPlainTextFromContent, renderRichContent, sanitizeRichHtml } from '@shared/tasks/rich-content';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/rich-content', () => {
    it('renders markdown content and keeps html content intact', () => {
        expect(renderRichContent('**hello**')).toContain('<strong>hello</strong>');
        expect(renderRichContent('<p>already html</p>')).toBe('<p>already html</p>');
    });

    it('sanitizes dangerous html while preserving supported rich content markup', () => {
        const html = sanitizeRichHtml([
            '<p>Hello</p>',
            '<script>alert(1)</script>',
            '<blockquote class="shift-reply extra" data-reply-to="42" onclick="alert(1)"><p>Reply</p></blockquote>',
            '<p><img src="/attachments/1/download" class="editor-tile extra" onerror="alert(1)"></p>',
            '<pre><code class="language-js extra">const x = 1;</code></pre>',
        ].join(''));

        expect(html).toContain('data-reply-to="42"');
        expect(html).toContain('class="shift-reply"');
        expect(html).toContain('class="editor-tile"');
        expect(html).toContain('class="language-js"');
        expect(html).not.toContain('<script');
        expect(html).not.toContain('onclick');
        expect(html).not.toContain('onerror');
    });

    it('sanitizes dangerous urls from rendered markdown output', () => {
        const html = renderRichContent('[click me](javascript:alert(1))');

        expect(html).toContain('<a');
        expect(html).not.toContain('javascript:');
    });

    it('extracts plain text from rich content', () => {
        expect(extractPlainTextFromContent('Line 1\n\nLine 2')).toContain('Line 1');
        expect(extractPlainTextFromContent('<p>hello</p>')).toBe('hello');
    });

    it('builds escaped reply quote html with newline conversion', () => {
        const html = buildReplyQuoteHtml({
            id: 42,
            author: 'A <B>',
            content: 'first line\nsecond line',
        });

        expect(html).toContain('data-reply-to="42"');
        expect(html).toContain('Replying to A &lt;B&gt;');
        expect(html).toContain('first line<br>second line');
    });

    it('returns empty reply quote html when id is missing', () => {
        expect(buildReplyQuoteHtml({ content: 'hello' })).toBe('');
    });
});
