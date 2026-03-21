<script setup lang="ts">
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { ChevronRight, Monitor, Moon, Sun } from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const { appearance, updateAppearance } = useAppearance();

const options = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const currentOption = computed(() => options.find((option) => option.value === appearance.value) ?? options[2]);
const nextOption = computed(() => {
    const currentIndex = options.findIndex((option) => option.value === currentOption.value.value);

    return options[(currentIndex + 1) % options.length];
});
const buttonLabel = computed(() => `Theme: ${currentOption.value.label}. Switch to ${nextOption.value.label}.`);

function cycleAppearance() {
    updateAppearance(nextOption.value.value);
}
</script>

<template>
    <button
        type="button"
        data-appearance-toggle
        :data-appearance="appearance"
        :data-next-appearance="nextOption.value"
        :title="buttonLabel"
        :aria-label="buttonLabel"
        @click="cycleAppearance"
        :class="[
            props.compact
                ? 'inline-flex h-8 items-center gap-2 rounded-md border border-border/70 bg-background/85 px-2.5 text-foreground shadow-xs transition-all hover:bg-accent/80 hover:text-foreground'
                : 'inline-flex h-11 items-center gap-3 rounded-lg border border-border/70 bg-background px-3.5 text-left text-foreground shadow-xs transition-all hover:bg-accent/80 hover:text-foreground',
        ]"
    >
        <component :is="currentOption.Icon" class="h-4 w-4 shrink-0" />

        <template v-if="!props.compact">
            <span class="min-w-0">
                <span class="block text-sm font-medium leading-none">{{ currentOption.label }}</span>
                <span class="text-muted-foreground mt-1 block text-xs leading-none">Next: {{ nextOption.label }}</span>
            </span>
            <ChevronRight class="text-muted-foreground ml-1 h-3.5 w-3.5 shrink-0" />
        </template>

        <span v-else class="sr-only">{{ buttonLabel }}</span>
    </button>
</template>
