import { marked } from 'marked';

const ALLOWED_TAGS = new Set([
    'a',
    'b',
    'blockquote',
    'br',
    'code',
    'em',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'hr',
    'i',
    'img',
    'li',
    'ol',
    'p',
    'pre',
    's',
    'strong',
    'u',
    'ul',
]);

const DROP_WITH_CONTENT = new Set(['base', 'embed', 'form', 'frame', 'frameset', 'iframe', 'input', 'link', 'math', 'meta', 'object', 'script', 'select', 'style', 'svg', 'textarea']);
const SAFE_IMAGE_DATA_URI_PATTERN = /^data:image\/(?:png|gif|jpe?g|webp);base64,[a-z0-9+/=\s]+$/i;

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

function childNodesOf(node: Node): ChildNode[] {
    return Array.from(node.childNodes);
}

function unwrapElement(element: Element): void {
    const parent = element.parentNode;
    if (!parent) return;

    while (element.firstChild) {
        parent.insertBefore(element.firstChild, element);
    }

    parent.removeChild(element);
}

function normalizeAllowedClasses(value: string, allowed: string[]): string | null {
    const classes = value.split(/\s+/).map((item) => item.trim()).filter(Boolean);
    const filtered = Array.from(new Set(classes.filter((item) => allowed.includes(item))));

    return filtered.length > 0 ? filtered.join(' ') : null;
}

function normalizeCodeClasses(value: string): string | null {
    const classes = value.split(/\s+/).map((item) => item.trim()).filter(Boolean);
    const filtered = Array.from(new Set(classes.filter((item) => item === 'hljs' || item.startsWith('language-'))));

    return filtered.length > 0 ? filtered.join(' ') : null;
}

function isSafeRelativeUrl(value: string): boolean {
    return (value.startsWith('/') && !value.startsWith('//')) || value.startsWith('#') || value.startsWith('?');
}

function hasAllowedScheme(value: string, allowed: string[]): boolean {
    const match = value.match(/^([a-z][a-z0-9+.-]*):/i);
    if (!match) return false;

    return allowed.includes(match[1].toLowerCase());
}

function isSafeHref(value: string): boolean {
    return isSafeRelativeUrl(value) || hasAllowedScheme(value, ['http', 'https', 'mailto', 'tel']);
}

function isSafeImageSrc(value: string): boolean {
    return isSafeRelativeUrl(value) || hasAllowedScheme(value, ['http', 'https']) || SAFE_IMAGE_DATA_URI_PATTERN.test(value);
}

function sanitizeFallbackHtml(html: string): string {
    return html
        .replace(/<script[\s\S]*?<\/script>/gi, '')
        .replace(/<style[\s\S]*?<\/style>/gi, '')
        .replace(/\son\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '')
        .replace(/\s(?:href|src)\s*=\s*(["'])\s*javascript:[\s\S]*?\1/gi, '');
}

function sanitizeElement(element: Element): void {
    const tag = element.tagName.toLowerCase();

    if (DROP_WITH_CONTENT.has(tag)) {
        element.remove();
        return;
    }

    if (!ALLOWED_TAGS.has(tag)) {
        unwrapElement(element);
        return;
    }

    for (const attribute of Array.from(element.attributes)) {
        const name = attribute.name.toLowerCase();
        const value = attribute.value.trim();
        const remove = () => element.removeAttribute(attribute.name);

        if (name.startsWith('on')) {
            remove();
            continue;
        }

        switch (tag) {
            case 'a': {
                if (!['href', 'target', 'rel', 'title'].includes(name)) {
                    remove();
                    continue;
                }

                if (name === 'href' && !isSafeHref(value)) {
                    remove();
                }

                if (name === 'target' && value !== '_blank') {
                    remove();
                }

                break;
            }
            case 'blockquote': {
                if (!['class', 'data-reply-to'].includes(name)) {
                    remove();
                    continue;
                }

                if (name === 'class') {
                    const normalized = normalizeAllowedClasses(value, ['shift-reply']);
                    if (!normalized) remove();
                    else element.setAttribute(attribute.name, normalized);
                }

                if (name === 'data-reply-to' && !/^\d+$/.test(value)) {
                    remove();
                }

                break;
            }
            case 'code': {
                if (name !== 'class') {
                    remove();
                    continue;
                }

                const normalized = normalizeCodeClasses(value);
                if (!normalized) remove();
                else element.setAttribute(attribute.name, normalized);
                break;
            }
            case 'img': {
                if (!['src', 'alt', 'title', 'class'].includes(name)) {
                    remove();
                    continue;
                }

                if (name === 'src' && !isSafeImageSrc(value)) {
                    remove();
                }

                if (name === 'class') {
                    const normalized = normalizeAllowedClasses(value, ['editor-tile']);
                    if (!normalized) remove();
                    else element.setAttribute(attribute.name, normalized);
                }

                break;
            }
            case 'ol': {
                if (name !== 'start') {
                    remove();
                    continue;
                }

                if (!/^\d+$/.test(value)) {
                    remove();
                }

                break;
            }
            default:
                remove();
        }
    }

    if (tag === 'img' && !element.getAttribute('src')) {
        element.remove();
        return;
    }

    if (tag === 'a') {
        if (!element.getAttribute('href')) {
            element.removeAttribute('target');
            element.removeAttribute('rel');
        } else if (element.getAttribute('target') === '_blank') {
            element.setAttribute('rel', 'noopener noreferrer');
        } else {
            element.removeAttribute('target');
            element.removeAttribute('rel');
        }
    }

    for (const child of childNodesOf(element)) {
        if (child.nodeType === Node.COMMENT_NODE) {
            child.remove();
            continue;
        }

        if (child.nodeType === Node.ELEMENT_NODE) {
            sanitizeElement(child as Element);
        }
    }
}

export function sanitizeRichHtml(html: string): string {
    const value = String(html ?? '');
    if (!value.trim()) return '';

    if (typeof document === 'undefined') {
        return sanitizeFallbackHtml(value);
    }

    const root = document.createElement('div');
    root.innerHTML = value;

    for (const child of childNodesOf(root)) {
        if (child.nodeType === Node.COMMENT_NODE) {
            child.remove();
            continue;
        }

        if (child.nodeType === Node.ELEMENT_NODE) {
            sanitizeElement(child as Element);
        }
    }

    return root.innerHTML;
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
    if (hasHtmlMarkup(value)) return sanitizeRichHtml(normalizeLegacyListMarkup(value));
    const rendered = marked.parse(value);
    return typeof rendered === 'string' ? sanitizeRichHtml(rendered) : value;
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
