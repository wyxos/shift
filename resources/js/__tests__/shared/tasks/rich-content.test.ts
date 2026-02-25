import { buildReplyQuoteHtml, extractPlainTextFromContent, renderRichContent } from '@shared/tasks/rich-content';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/rich-content', () => {
    it('renders markdown content and keeps html content intact', () => {
        expect(renderRichContent('**hello**')).toContain('<strong>hello</strong>');
        expect(renderRichContent('<p>already html</p>')).toBe('<p>already html</p>');
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
