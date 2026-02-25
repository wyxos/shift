import { formatThreadTime, getReplyTargetFromEventTarget, mapThreadToMessage, parseReplyTargetId, shouldHandleImage } from '@shared/tasks/thread';
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
            attachments: [{ id: 1 }],
        });

        expect(mapped.id).toBe(7);
        expect(mapped.author).toBe('Alice');
        expect(mapped.isYou).toBe(true);
        expect(mapped.attachments).toEqual([{ id: 1 }]);
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
