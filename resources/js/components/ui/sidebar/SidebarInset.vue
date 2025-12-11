<script lang="ts" setup>
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { useSidebar } from './utils';
import { computed } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class']
}>();

const { state, isMobile } = useSidebar();

// Compute margin style for inset variant - adjusts when sidebar is collapsed
// Only applies margins on desktop (md+), mobile has no margins
const insetStyle = computed(() => {
    if (isMobile.value) {
        return {};
    }
    return {
        margin: '0.5rem',
        marginLeft: state.value === 'collapsed' ? '0.5rem' : '0',
    };
});
</script>

<template>
    <main
        :class="cn(
            'bg-background relative flex flex-1 min-h-svh overflow-hidden flex-col',
            'md:rounded-xl md:shadow-sm',
            props.class,
        )"
        :style="insetStyle"
        data-slot="sidebar-inset"
    >
        <slot />
    </main>
</template>
