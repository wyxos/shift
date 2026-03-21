<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance';
import { Monitor, Moon, Sun } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

function titleFor(label: string) {
    return `Use ${label.toLowerCase()} theme`;
}
</script>

<template>
    <div
        :class="
            props.compact
                ? 'inline-flex items-center gap-1 rounded-full border border-border/70 bg-background/80 p-1 shadow-xs backdrop-blur-sm'
                : 'inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800'
        "
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            :title="titleFor(label)"
            :aria-label="titleFor(label)"
            :aria-pressed="appearance === value"
            @click="updateAppearance(value)"
            :class="[
                props.compact
                    ? 'flex h-8 items-center justify-center rounded-full px-2.5 text-muted-foreground transition-all'
                    : 'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                appearance === value
                    ? props.compact
                        ? 'bg-primary text-primary-foreground shadow-sm'
                        : 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                    : props.compact
                      ? 'hover:bg-accent hover:text-foreground'
                      : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
            ]"
        >
            <component :is="Icon" :class="props.compact ? 'h-4 w-4' : '-ml-1 h-4 w-4'" />
            <span v-if="!props.compact" class="ml-1.5 text-sm">{{ label }}</span>
            <span v-else class="sr-only">{{ label }}</span>
        </button>
    </div>
</template>
