export type MappedThreadMessage<TAttachment = unknown> = {
    clientId: string;
    id?: number;
    author: string;
    time: string;
    content: string;
    isYou: boolean;
    attachments: TAttachment[];
};

export function formatThreadTime(value: any): string {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(String(value));
    if (Number.isNaN(date.getTime())) return String(value);

    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startYesterday = new Date(startToday);
    startYesterday.setDate(startToday.getDate() - 1);

    const time = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

    if (date >= startToday) return time;
    if (date >= startYesterday && date < startToday) return `Yesterday - ${time}`;

    const day = new Intl.DateTimeFormat('en-GB', { day: '2-digit' }).format(date);
    const month = new Intl.DateTimeFormat('en-GB', { month: 'short' }).format(date);
    return `${day} ${month} ${time}`;
}

export function mapThreadToMessage<TAttachment = unknown>(thread: any): MappedThreadMessage<TAttachment> {
    const id = typeof thread?.id === 'number' ? (thread.id as number) : undefined;
    const author = String(thread?.sender_name ?? thread?.author ?? 'Unknown');
    const isYou = Boolean(thread?.is_current_user ?? thread?.isYou);
    const content = String(thread?.content ?? '');
    const time = formatThreadTime(thread?.created_at);
    const attachments = Array.isArray(thread?.attachments) ? (thread.attachments as TAttachment[]) : [];
    return {
        clientId: id ? `thread-${id}` : `thread-${Date.now()}`,
        id,
        author,
        time,
        content,
        isYou,
        attachments,
    };
}

export function parseReplyTargetId(value: string | null | undefined): number | null {
    if (!value) return null;
    const match = value.match(/^#?comment-(\d+)$/);
    if (!match) return null;
    const id = Number.parseInt(match[1], 10);
    return Number.isFinite(id) && id > 0 ? id : null;
}

export function getReplyTargetFromEventTarget(target: HTMLElement): number | null {
    const anchor = target.closest('a[href^="#comment-"]') as HTMLAnchorElement | null;
    const fromAnchor = parseReplyTargetId(anchor?.getAttribute('href'));
    if (fromAnchor) return fromAnchor;

    const quote = target.closest('blockquote[data-reply-to]') as HTMLElement | null;
    const fromQuote = parseReplyTargetId(`comment-${quote?.dataset.replyTo ?? ''}`);
    if (fromQuote) return fromQuote;

    return null;
}

export function shouldHandleImage(img: HTMLImageElement): { ok: boolean; inEditable: boolean } {
    const inRich = Boolean(img.closest('.shift-rich')) || Boolean(img.closest('.tiptap')) || img.classList.contains('editor-tile');
    if (!inRich) return { ok: false, inEditable: false };
    const inEditable = Boolean(img.closest('[contenteditable="true"]'));
    return { ok: true, inEditable };
}
