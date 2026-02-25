import { marked } from 'marked';

export function hasHtmlMarkup(content: string): boolean {
    return /<\/?[a-z][\s\S]*>/i.test(content);
}

export function decodeHtmlToText(value: string): string {
    if (typeof document === 'undefined') {
        return value.replace(/<[^>]+>/g, ' ');
    }
    const temp = document.createElement('div');
    temp.innerHTML = value;
    return temp.textContent ?? '';
}

export function normalizeLegacyListMarkup(html: string): string {
    if (typeof document === 'undefined') return html;
    if (!/(<ul|<ol)/i.test(html) || !/<br\s*\/?>/i.test(html)) return html;

    const root = document.createElement('div');
    root.innerHTML = html;
    let changed = false;

    root.querySelectorAll('ul, ol').forEach((list) => {
        const listTag = list.tagName.toLowerCase();
        const children = Array.from(list.children).filter((child) => child.tagName.toLowerCase() === 'li');

        children.forEach((item) => {
            if (item.querySelector('ul, ol')) return;

            const fragments = item.innerHTML
                .split(/<br\s*\/?>/gi)
                .map((fragment) => decodeHtmlToText(fragment).trim())
                .filter(Boolean);

            if (fragments.length < 2) return;

            const hasMarkedTail = fragments.slice(1).some((line) => {
                return listTag === 'ul' ? /^[-*+]\s+/.test(line) : /^\d+[.)]\s+/.test(line);
            });
            if (!hasMarkedTail) return;

            const normalizedLines = fragments
                .map((line) => {
                    return listTag === 'ul' ? line.replace(/^[-*+]\s+/, '') : line.replace(/^\d+[.)]\s+/, '');
                })
                .map((line) => line.trim())
                .filter(Boolean);

            if (normalizedLines.length < 2) return;

            const replacement = normalizedLines.map((line) => {
                const li = document.createElement('li');
                li.textContent = line;
                return li;
            });

            item.replaceWith(...replacement);
            changed = true;
        });
    });

    return changed ? root.innerHTML : html;
}

export function renderRichContent(content: string | null | undefined): string {
    const value = String(content ?? '');
    if (!value.trim()) return '';
    if (hasHtmlMarkup(value)) return normalizeLegacyListMarkup(value);
    const rendered = marked.parse(value);
    return typeof rendered === 'string' ? rendered : value;
}

export function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

export function extractPlainTextFromContent(content: string): string {
    const rendered = renderRichContent(content);
    return decodeHtmlToText(rendered)
        .replace(/\r/g, '')
        .replace(/\n{3,}/g, '\n\n')
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n[ \t]+/g, '\n')
        .trim();
}

export type ReplyQuoteSource = {
    id?: number;
    author?: string;
    content: string;
};

export function buildReplyQuoteHtml(message: ReplyQuoteSource): string {
    if (!message.id) return '';
    const plain = extractPlainTextFromContent(message.content);
    const snippet = plain.length > 280 ? `${plain.slice(0, 277)}...` : plain;
    const quoted = escapeHtml(snippet).replace(/\n/g, '<br>');
    const author = escapeHtml(message.author || 'User');

    return [
        `<blockquote class="shift-reply" data-reply-to="${message.id}">`,
        `<p>Replying to ${author}</p>`,
        `<p>${quoted}</p>`,
        '</blockquote>',
        '<p></p>',
    ].join('');
}
