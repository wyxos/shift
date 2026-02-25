export type ContextMessageLike = {
    id?: number;
    isYou?: boolean;
    pending?: boolean;
};

export function getSelectionForMessage(messageId?: number): string {
    if (typeof window === 'undefined' || !messageId) return '';
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return '';
    const selectedText = selection.toString().trim();
    if (!selectedText) return '';
    const bubble = document.getElementById(`comment-${messageId}`);
    if (!bubble) return '';
    const anchorInside = selection.anchorNode ? bubble.contains(selection.anchorNode) : false;
    const focusInside = selection.focusNode ? bubble.contains(selection.focusNode) : false;
    return anchorInside && focusInside ? selectedText : '';
}

export function shouldShowCopySelection(message: ContextMessageLike, contextMenuMessageId: number | null, contextMenuSelectionText: string): boolean {
    if (message.isYou || !message.id || message.pending) return false;
    return contextMenuMessageId === message.id && contextMenuSelectionText.length > 0;
}

export async function copyTextToClipboard(text: string): Promise<boolean> {
    const value = text.trim();
    if (!value) return false;

    try {
        if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
    } catch {
        // fallback below
    }

    if (typeof document === 'undefined') return false;
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);
    return copied;
}

export function getLightboxImageData(img: HTMLImageElement): { src: string; alt: string } | null {
    const src = img.currentSrc || img.src;
    if (!src) return null;
    return {
        src,
        alt: img.alt || img.title || 'Image',
    };
}
