<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Ellipsis } from 'lucide-vue-next';
import { ContextMenuContent, ContextMenuPortal, ContextMenuRoot, ContextMenuTrigger } from 'reka-ui';
import { ref } from 'vue';

defineProps<{ label: string; triggerId: string; disabled?: boolean; own?: boolean }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();
const container = ref<HTMLElement | null>(null);
const focusDestination = ref<string | null>(null);

function onOpenChange(open: boolean) {
    if (open) focusDestination.value = null;
    emit('update:open', open);
}

function onActionClick(event: MouseEvent) {
    focusDestination.value =
        event.target instanceof Element
            ? (event.target.closest<HTMLElement>('[data-comment-focus-action]')?.dataset.commentFocusAction ?? null)
            : null;
}

function onCloseAutoFocus(event: Event) {
    if (!focusDestination.value) return;
    event.preventDefault();
    if (focusDestination.value === 'editor') {
        // Transfer focus after the menu releases its focus scope, retaining the editor's selection.
        container.value
            ?.closest('[data-task-sheet]')
            ?.querySelector<HTMLElement>('[data-testid="comments-editor"] [role="textbox"]')
            ?.focus({ preventScroll: true });
    }
}
</script>

<template>
    <div ref="container" class="relative max-w-full" :class="{ 'task-comment-menu-own': own }">
        <ContextMenuRoot @update:open="onOpenChange">
            <ContextMenuTrigger as-child :disabled="disabled">
                <slot />
            </ContextMenuTrigger>
            <ContextMenuPortal>
                <ContextMenuContent
                    data-task-menu
                    class="bg-popover text-popover-foreground z-50 min-w-40 overflow-hidden rounded-md border p-1 shadow-md"
                    @click.capture="onActionClick"
                    @close-auto-focus="onCloseAutoFocus"
                >
                    <slot name="items" />
                </ContextMenuContent>
            </ContextMenuPortal>
        </ContextMenuRoot>
        <DropdownMenu @update:open="onOpenChange">
            <DropdownMenuTrigger as-child>
                <Button
                    :aria-label="label"
                    :data-testid="triggerId"
                    :disabled="disabled"
                    class="task-comment-actions sr-only"
                    variant="outline"
                    size="icon"
                    type="button"
                >
                    <Ellipsis aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="min-w-40" data-task-menu @click.capture="onActionClick" @close-auto-focus="onCloseAutoFocus">
                <slot name="items" />
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
