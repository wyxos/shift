import {
    formatThreadTime,
    getReplyTargetFromEventTarget,
    mapThreadToMessage,
    parseReplyTargetId,
    shouldHandleImage,
    shouldShowThreadMessageMeta,
} from '@shared/tasks/thread';
import { describe, expect, it } from 'vitest';

describe('shared/tasks/thread', () => {
    it('formats a current timestamp as HH:mm', () => {
        const formatted = formatThreadTime(new Date());
        expect(formatted).toMatch(/^\d{2}:\d{2}$/);
    });

    it('maps thread payload to UI message model', () => {
        const mapped = mapThreadToMessage<{ id: number }>({
            id: 7,
            sender_name: 'Alice',
            content: '<p>hello</p>',
            is_current_user: true,
            created_at: new Date().toISOString(),
            audience: 'team',
            attachments: [{ id: 1 }],
            mentions: [{ kind: 'internal', id: 4, name: 'Alice' }],
        });

        expect(mapped.id).toBe(7);
        expect(mapped.author).toBe('Alice');
        expect(mapped.createdAt).toBeTypeOf('string');
        expect(mapped.isYou).toBe(true);
        expect(mapped.audience).toBe('team');
        expect(mapped.attachments).toEqual([{ id: 1 }]);
        expect(mapped.mentions).toEqual([{ kind: 'internal', id: 4, name: 'Alice' }]);
    });

    it('groups nearby consecutive messages while preserving meaningful breaks', () => {
        const message = (author: string, createdAt: string, options: { isYou?: boolean; audience?: 'all' | 'team' } = {}) => ({
            author,
            createdAt,
            isYou: options.isYou ?? false,
            audience: options.audience ?? 'all',
        });
        const messages = [
            message('Alice', '2026-08-03T10:00:00Z'),
            message('Alice', '2026-08-03T10:05:00Z'),
            message('Alice', '2026-08-03T10:10:01Z'),
            message('Bob', '2026-08-03T10:11:00Z'),
            message('Alice', '2026-08-03T10:12:00Z'),
            message('Alice', '2026-08-03T10:13:00Z', { audience: 'team' }),
            message('You', '2026-08-03T10:14:00Z', { isYou: true }),
            message('Current user', '2026-08-03T10:15:00Z', { isYou: true }),
            message('Current user', '2026-08-04T10:16:00Z', { isYou: true }),
        ];

        expect(messages.map((_, index) => shouldShowThreadMessageMeta(messages, index))).toEqual([
            true,
            false,
            true,
            true,
            true,
            true,
            true,
            false,
            true,
        ]);
        expect(shouldShowThreadMessageMeta([{ ...messages[0], createdAt: null }, messages[1]], 1)).toBe(true);
    });

    it('parses reply target ids', () => {
        expect(parseReplyTargetId('#comment-123')).toBe(123);
        expect(parseReplyTargetId('comment-5')).toBe(5);
        expect(parseReplyTargetId('#comment-0')).toBeNull();
        expect(parseReplyTargetId('#wrong-9')).toBeNull();
    });

    it('finds reply target id from anchor or quote element', () => {
        const anchor = document.createElement('a');
        anchor.setAttribute('href', '#comment-88');
        const span = document.createElement('span');
        anchor.appendChild(span);

        const quote = document.createElement('blockquote');
        quote.setAttribute('data-reply-to', '21');
        const quoteSpan = document.createElement('span');
        quote.appendChild(quoteSpan);

        expect(getReplyTargetFromEventTarget(span)).toBe(88);
        expect(getReplyTargetFromEventTarget(quoteSpan)).toBe(21);
    });

    it('detects whether an image should be handled for lightbox', () => {
        const rich = document.createElement('div');
        rich.className = 'shift-rich';
        const img = document.createElement('img');
        rich.appendChild(img);
        expect(shouldHandleImage(img)).toEqual({ ok: true, inEditable: false });

        const editable = document.createElement('div');
        editable.setAttribute('contenteditable', 'true');
        editable.className = 'shift-rich';
        const editableImg = document.createElement('img');
        editable.appendChild(editableImg);
        expect(shouldHandleImage(editableImg)).toEqual({ ok: true, inEditable: true });
    });
});
