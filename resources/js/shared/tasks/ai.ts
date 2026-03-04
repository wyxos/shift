import { extractPlainTextFromContent } from './rich-content';

type ThreadContextMessage = {
    author?: string | null;
    content?: string | null;
    time?: string | null;
};

type BuildThreadContextOptions = {
    maxMessages?: number;
    maxCharacters?: number;
};

const DEFAULT_MAX_MESSAGES = 8;
const DEFAULT_MAX_CHARACTERS = 3000;
const MAX_LINE_CHARACTERS = 320;

function normalizeInline(text: string): string {
    return text.replace(/\s+/g, ' ').trim();
}

function compactContent(content: string): string {
    const compact = normalizeInline(extractPlainTextFromContent(content));
    if (!compact) return '';
    if (compact.length <= MAX_LINE_CHARACTERS) return compact;
    return `${compact.slice(0, MAX_LINE_CHARACTERS - 1).trimEnd()}…`;
}

export function buildThreadAiContext(messages: ThreadContextMessage[], options: BuildThreadContextOptions = {}): string {
    if (!Array.isArray(messages) || messages.length === 0) return '';

    const maxMessages = Math.max(1, options.maxMessages ?? DEFAULT_MAX_MESSAGES);
    const maxCharacters = Math.max(200, options.maxCharacters ?? DEFAULT_MAX_CHARACTERS);

    const lines = messages
        .slice(-maxMessages)
        .map((message, index) => {
            const content = compactContent(message.content ?? '');
            if (!content) return '';

            const author = normalizeInline(message.author ?? '') || 'Unknown';
            const time = normalizeInline(message.time ?? '');
            const timePart = time ? ` (${time})` : '';

            return `${index + 1}. ${author}${timePart}: ${content}`;
        })
        .filter(Boolean);

    if (lines.length === 0) return '';

    const header = 'Recent thread context (oldest to newest):';

    while (lines.length > 1 && [header, ...lines].join('\n').length > maxCharacters) {
        lines.shift();
    }

    let context = [header, ...lines].join('\n');
    if (context.length > maxCharacters) {
        context = context.slice(0, maxCharacters).trimEnd();
    }

    return context;
}
