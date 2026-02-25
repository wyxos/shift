import {
    copyTextToClipboard,
    getLightboxImageData,
    getSelectionForMessage,
    resolveTouchTap,
    shouldIgnoreEditGesture,
    shouldShowCopySelection,
    type ContextMessageLike,
} from '@shared/tasks/interaction';
import { afterEach, describe, expect, it, vi } from 'vitest';

function setSelectionInside(element: HTMLElement, text: string): void {
    element.textContent = text;
    const range = document.createRange();
    range.selectNodeContents(element);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
}

describe('shared/tasks/interaction', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        window.getSelection()?.removeAllRanges();
        vi.restoreAllMocks();
    });

    it('returns selected text only when selection is inside the message bubble', () => {
        const bubble = document.createElement('div');
        bubble.id = 'comment-7';
        document.body.appendChild(bubble);

        setSelectionInside(bubble, 'Hello from message');
        expect(getSelectionForMessage(7)).toBe('Hello from message');

        const outside = document.createElement('div');
        outside.textContent = 'outside';
        document.body.appendChild(outside);
        setSelectionInside(outside, 'outside');
        expect(getSelectionForMessage(7)).toBe('');
    });

    it('returns false when message is not eligible for copy selection', () => {
        const base: ContextMessageLike = { id: 10 };
        expect(shouldShowCopySelection(base, 10, 'selected')).toBe(true);
        expect(shouldShowCopySelection({ id: 10, isYou: true }, 10, 'selected')).toBe(false);
        expect(shouldShowCopySelection({ id: 10, pending: true }, 10, 'selected')).toBe(false);
        expect(shouldShowCopySelection({ id: 10 }, 11, 'selected')).toBe(false);
        expect(shouldShowCopySelection({ id: 10 }, 10, '')).toBe(false);
    });

    it('ignores edit gestures on interactive targets', () => {
        const image = document.createElement('img');
        expect(shouldIgnoreEditGesture({ target: image } as unknown as Event)).toBe(true);

        const link = document.createElement('a');
        const nested = document.createElement('span');
        link.appendChild(nested);
        expect(shouldIgnoreEditGesture({ target: nested } as unknown as Event)).toBe(true);

        const button = document.createElement('button');
        expect(shouldIgnoreEditGesture({ target: button } as unknown as Event)).toBe(true);

        const plain = document.createElement('div');
        expect(shouldIgnoreEditGesture({ target: plain } as unknown as Event)).toBe(false);
    });

    it('resolves touch tap state and detects double taps', () => {
        const first = resolveTouchTap(7, { lastTapAt: 0, lastTapId: null }, 1000);
        expect(first.isDoubleTap).toBe(false);
        expect(first.nextTapState).toEqual({ lastTapAt: 1000, lastTapId: 7 });

        const second = resolveTouchTap(7, first.nextTapState, 1200);
        expect(second.isDoubleTap).toBe(true);
        expect(second.nextTapState).toEqual({ lastTapAt: 0, lastTapId: null });

        const otherMessage = resolveTouchTap(8, first.nextTapState, 1200);
        expect(otherMessage.isDoubleTap).toBe(false);
        expect(otherMessage.nextTapState).toEqual({ lastTapAt: 1200, lastTapId: 8 });

        const afterThreshold = resolveTouchTap(7, first.nextTapState, 1400);
        expect(afterThreshold.isDoubleTap).toBe(false);
        expect(afterThreshold.nextTapState).toEqual({ lastTapAt: 1400, lastTapId: 7 });
    });

    it('copies via Clipboard API when available', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        await expect(copyTextToClipboard(' hello ')).resolves.toBe(true);
        expect(writeText).toHaveBeenCalledWith('hello');
    });

    it('falls back to execCommand when Clipboard API fails', async () => {
        const writeText = vi.fn().mockRejectedValue(new Error('blocked'));
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        const execCommand = vi.fn().mockReturnValue(true);
        Object.defineProperty(document, 'execCommand', {
            configurable: true,
            value: execCommand,
        });

        await expect(copyTextToClipboard('fallback text')).resolves.toBe(true);
        expect(execCommand).toHaveBeenCalledWith('copy');
    });

    it('returns false for empty clipboard values', async () => {
        await expect(copyTextToClipboard('   ')).resolves.toBe(false);
    });

    it('extracts lightbox image data with sensible alt fallback', () => {
        expect(getLightboxImageData(document.createElement('img'))).toBeNull();

        const image = document.createElement('img');
        image.src = 'https://example.com/a.png';
        image.alt = 'Screenshot';

        expect(getLightboxImageData(image)).toEqual({
            src: image.src,
            alt: 'Screenshot',
        });

        image.alt = '';
        image.title = 'Fallback title';
        expect(getLightboxImageData(image)).toEqual({
            src: image.src,
            alt: 'Fallback title',
        });
    });
});
