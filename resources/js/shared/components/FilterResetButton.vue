<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineOptions({ inheritAttrs: false });
defineProps<{ visible: boolean }>();
defineEmits<{ click: [] }>();

function restoreFocus(element: Element) {
    if (element.contains(document.activeElement) && element.nextElementSibling instanceof HTMLElement) {
        element.nextElementSibling.focus();
    }
}
</script>

<template>
    <Transition name="filter-reset" @before-leave="restoreFocus">
        <Button v-if="visible" data-testid="filters-reset" type="button" variant="destructive" v-bind="$attrs" @click="$emit('click')">
            Reset
        </Button>
    </Transition>
</template>

<style scoped>
.filter-reset-enter-active,
.filter-reset-leave-active {
    transition: opacity 150ms ease-out;
}

.filter-reset-enter-from,
.filter-reset-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .filter-reset-enter-active,
    .filter-reset-leave-active {
        transition-duration: 0.01ms;
    }
}
</style>
