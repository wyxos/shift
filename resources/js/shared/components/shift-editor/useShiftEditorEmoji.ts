import { nextTick, onBeforeUnmount, onMounted, ref, type ComponentPublicInstance } from 'vue';

export function useShiftEditorEmoji(insertEmoji: (unicode: string) => void) {
    const showEmoji = ref(false);
    const emojiPopover = ref<HTMLElement | null>(null);
    const emojiToolbar = ref<HTMLElement | null>(null);
    const emojiPlacement = ref<'above' | 'below'>('above');
    const emojiPopoverStyle = ref({ left: '0px', top: '0px', visibility: 'hidden' as 'hidden' | 'visible' });

    function setEmojiPopover(element: Element | ComponentPublicInstance | null) {
        emojiPopover.value = element instanceof HTMLElement ? element : null;
    }

    function setEmojiToolbar(element: Element | ComponentPublicInstance | null) {
        emojiToolbar.value = element instanceof HTMLElement ? element : null;
    }

    function onEmojiClick(event: Event) {
        const unicode = (event as CustomEvent).detail?.unicode || (event as any).detail?.emoji?.unicode;
        if (!unicode) return;
        insertEmoji(unicode);
        showEmoji.value = false;
    }

    async function toggleEmojiPicker() {
        if (showEmoji.value) {
            showEmoji.value = false;
            return;
        }

        emojiPopoverStyle.value.visibility = 'hidden';
        showEmoji.value = true;
        await nextTick();
        positionEmojiPicker();
    }

    function positionEmojiPicker() {
        if (!showEmoji.value || !emojiToolbar.value || !emojiPopover.value) return;

        const toolbar = emojiToolbar.value.getBoundingClientRect();
        const popover = emojiPopover.value.getBoundingClientRect();
        const height = popover.height || 440;
        const width = popover.width || 350;
        const spaceAbove = toolbar.top - 8;
        const spaceBelow = window.innerHeight - toolbar.bottom - 8;
        emojiPlacement.value = spaceAbove >= height || spaceAbove > spaceBelow ? 'above' : 'below';
        emojiPopoverStyle.value = {
            left: `${Math.max(8, Math.min(toolbar.left, window.innerWidth - width - 8))}px`,
            top: `${emojiPlacement.value === 'above' ? Math.max(8, toolbar.top - height - 8) : Math.min(window.innerHeight - height - 8, toolbar.bottom + 8)}px`,
            visibility: 'visible',
        };
    }

    function closeOnOutsidePointer(event: PointerEvent) {
        if (!showEmoji.value || emojiToolbar.value?.contains(event.target as Node) || emojiPopover.value?.contains(event.target as Node)) return;
        showEmoji.value = false;
    }

    function closeOnEscape(event: KeyboardEvent) {
        if (event.key !== 'Escape' || !showEmoji.value) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        showEmoji.value = false;
    }

    onMounted(() => {
        document.addEventListener('pointerdown', closeOnOutsidePointer);
        window.addEventListener('keydown', closeOnEscape, true);
        document.addEventListener('scroll', positionEmojiPicker, true);
        window.addEventListener('resize', positionEmojiPicker);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('pointerdown', closeOnOutsidePointer);
        window.removeEventListener('keydown', closeOnEscape, true);
        document.removeEventListener('scroll', positionEmojiPicker, true);
        window.removeEventListener('resize', positionEmojiPicker);
    });

    return { emojiPlacement, emojiPopoverStyle, onEmojiClick, setEmojiPopover, setEmojiToolbar, showEmoji, toggleEmojiPicker };
}
